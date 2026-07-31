<?php

namespace App\Services;

use App\Models\BookingSeries;
use App\Models\BookingSeriesMutation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class BookingSeriesCancellationService
{
    public function __construct(private BookingFinancialCompensationService $compensation) {}

    public function cancel(User $actor, BookingSeries $series, string $scope, ?int $effectiveOccurrenceId, string $reason, string $idempotencyKey, bool $allOrNothing = false): array
    {
        if ($existing = BookingSeriesMutation::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing->result_snapshot;
        }
        $own = $actor->member_id && (int) $series->member_id === (int) $actor->member_id;
        abort_unless($actor->active && ($own || app(BookingAccessService::class)->canManage($actor, $series->facility)), 403);

        return DB::transaction(function () use ($actor, $series, $scope, $effectiveOccurrenceId, $reason, $idempotencyKey, $allOrNothing) {
            $series = BookingSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();
            if ($existing = BookingSeriesMutation::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing->result_snapshot;
            }
            $query = $series->occurrences()->with('booking')->orderBy('occurrence_number');
            if ($effectiveOccurrenceId) {
                $effective = $series->occurrences()->findOrFail($effectiveOccurrenceId);
                if ($scope === 'occurrence') {
                    $query->whereKey($effective->id);
                }
                if (in_array($scope, ['this_and_future', 'all_future'], true)) {
                    $query->where('occurrence_number', '>=', $effective->occurrence_number);
                }
            } elseif ($scope !== 'entire_unstarted') {
                $query->whereHas('booking', fn ($booking) => $booking->where('starts_at', '>', now()));
            }
            $occurrences = $query->lockForUpdate()->get();
            $rows = [];
            foreach ($occurrences as $occurrence) {
                $booking = $occurrence->booking;
                if (! $booking || in_array($occurrence->status, ['cancelled', 'superseded'], true) || $booking->status === 'cancelled') {
                    $rows[] = ['occurrence_id' => $occurrence->id, 'outcome' => 'already_cancelled'];

                    continue;
                }
                if ($booking->starts_at->isPast() || in_array($booking->status, ['completed', 'no_show'], true)) {
                    $rows[] = ['occurrence_id' => $occurrence->id, 'outcome' => 'protected'];

                    continue;
                }
                try {
                    $result = $this->compensation->cancel($actor, $booking, $reason, $idempotencyKey.":{$occurrence->id}", 'series_cancellation');
                    $occurrence->update(['status' => 'cancelled', 'exception_type' => 'cancelled']);
                    $rows[] = [
                        'occurrence_id' => $occurrence->id, 'booking_id' => $booking->id, 'outcome' => 'cancelled',
                        'financial_outcome' => $result->outcome_type, 'amount' => $result->eligible_amount,
                        'allowance_restored' => $result->allowance_minutes_restored,
                        'gross_amount' => $booking->total_amount,
                    ];
                    Audit::record('booking_series.occurrence_cancelled', $occurrence, [], ['booking_id' => $booking->id, 'financial_outcome' => $result->outcome_type, 'amount' => $result->eligible_amount, 'reason' => $reason]);
                } catch (\Throwable $exception) {
                    report($exception);
                    $rows[] = ['occurrence_id' => $occurrence->id, 'outcome' => 'failed', 'message' => 'The occurrence could not be cancelled.'];
                }
            }
            $collection = collect($rows);
            $summary = [
                'scope' => $scope, 'requested' => $collection->count(), 'cancelled' => $collection->where('outcome', 'cancelled')->count(),
                'already_cancelled' => $collection->where('outcome', 'already_cancelled')->count(), 'protected' => $collection->where('outcome', 'protected')->count(),
                'failed' => $collection->where('outcome', 'failed')->count(),
                'no_compensation' => $collection->where('financial_outcome', 'no_compensation')->count(),
                'credits' => $collection->whereIn('financial_outcome', ['credited', 'partially_credited'])->count(),
                'refund_entitlements' => $collection->where('financial_outcome', 'refund_pending')->count(),
                'credit_total' => $collection->whereIn('financial_outcome', ['credited', 'partially_credited'])->sum('amount'),
                'refund_entitlement_total' => $collection->where('financial_outcome', 'refund_pending')->sum('amount'),
                'refunded_total' => $collection->where('financial_outcome', 'refunded')->sum('amount'),
                'allowance_restored' => $collection->sum('allowance_restored'),
                'gross_affected_amount' => $collection->where('outcome', 'cancelled')->sum('gross_amount'),
            ];
            $summary['remaining_uncompensated_amount'] = max(0, $summary['gross_affected_amount'] - $summary['credit_total'] - $summary['refund_entitlement_total'] - $summary['refunded_total']);
            $result = ['summary' => $summary, 'occurrences' => $rows];
            BookingSeriesMutation::create([
                'booking_series_id' => $series->id, 'initiated_by_user_id' => $actor->id, 'mutation_type' => 'cancellation',
                'scope' => $scope, 'idempotency_key' => $idempotencyKey, 'series_version' => $series->version,
                'request_snapshot' => ['effective_occurrence_id' => $occurrences->first()?->id],
                'result_snapshot' => $result, 'reason' => $reason,
            ]);
            if ($collection->whereNotIn('outcome', ['cancelled', 'already_cancelled', 'protected'])->isEmpty()
                && $series->occurrences()->whereNotIn('status', ['cancelled', 'completed', 'superseded'])->doesntExist()) {
                $series->update(['status' => 'cancelled', 'approval_status' => 'cancelled']);
            }
            Audit::record('booking_series.cancelled_scope', $series, [], $summary);
            abort_if($allOrNothing && $summary['failed'] > 0, 422, 'The all-or-nothing cancellation could not be completed.');

            return $result;
        }, 3);
    }
}
