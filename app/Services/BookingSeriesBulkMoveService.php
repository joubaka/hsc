<?php

namespace App\Services;

use App\Models\BookingSeries;
use App\Models\BookingSeriesMutation;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class BookingSeriesBulkMoveService
{
    public function __construct(private BookingOccurrenceAmendmentService $amendments) {}

    public function move(User $actor, BookingSeries $series, int $facilityId, string $scope, array $occurrenceIds, ?int $effectiveOccurrenceId, string $reason, string $idempotencyKey, bool $partial): array
    {
        abort_unless(app(BookingAccessService::class)->canManage($actor, $series->facility), 403);
        if ($existing = BookingSeriesMutation::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing->result_snapshot;
        }

        $runner = function () use ($actor, $series, $facilityId, $scope, $occurrenceIds, $effectiveOccurrenceId, $reason, $idempotencyKey, $partial) {
            $series = BookingSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();
            $query = $series->occurrences()->with('booking')->where('starts_at', '>', now())->where('status', 'confirmed')->orderBy('occurrence_number');
            if ($scope === 'selected') {
                $query->whereIn('id', $occurrenceIds);
            } elseif ($scope === 'occurrence') {
                $query->whereKey($effectiveOccurrenceId);
            } elseif ($scope === 'this_and_future') {
                $effective = $series->occurrences()->findOrFail($effectiveOccurrenceId);
                $query->where('occurrence_number', '>=', $effective->occurrence_number);
            }
            $occurrences = $query->lockForUpdate()->get();
            abort_if($occurrences->isEmpty(), 422, 'No eligible future occurrences were selected.');
            $outcomes = [];
            foreach ($occurrences as $occurrence) {
                try {
                    $result = $this->amendments->amend($actor, $occurrence, ['facility_id' => $facilityId], "{$idempotencyKey}:{$occurrence->id}", $reason);
                    $outcomes[] = ['occurrence_id' => $occurrence->id, 'outcome' => 'moved', 'booking_id' => $result['booking']->id];
                } catch (\Throwable $exception) {
                    if (! $partial) {
                        throw $exception;
                    }
                    report($exception);
                    $outcomes[] = ['occurrence_id' => $occurrence->id, 'outcome' => 'failed', 'message' => 'The destination was unavailable; the original booking was preserved.'];
                }
            }
            $result = [
                'requested' => count($outcomes),
                'moved' => collect($outcomes)->where('outcome', 'moved')->count(),
                'failed' => collect($outcomes)->where('outcome', 'failed')->count(),
                'occurrences' => $outcomes,
            ];
            BookingSeriesMutation::create([
                'booking_series_id' => $series->id,
                'initiated_by_user_id' => $actor->id,
                'mutation_type' => 'facility_move',
                'scope' => $scope,
                'idempotency_key' => $idempotencyKey,
                'series_version' => $series->version,
                'request_snapshot' => ['facility_id' => $facilityId, 'effective_occurrence_id' => $effectiveOccurrenceId, 'occurrence_ids' => $occurrenceIds],
                'result_snapshot' => $result,
                'reason' => $reason,
            ]);
            Audit::record('booking_series.facility_moved', $series, [], [...$result, 'destination_facility_id' => $facilityId, 'reason' => $reason]);

            return $result;
        };

        return DB::transaction($runner, 3);
    }
}
