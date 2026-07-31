<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingAllowanceUsage;
use App\Models\BookingSeriesMutation;
use App\Models\BookingSeriesOccurrence;
use App\Models\CreditNote;
use App\Models\Facility;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingOccurrenceAmendmentService
{
    public function __construct(
        private BookingRuleEvaluator $evaluator,
        private DocumentNumberService $numbers,
    ) {}

    public function amend(User $actor, BookingSeriesOccurrence $occurrence, array $changes, string $idempotencyKey, ?string $reason = null): BookingSeriesOccurrence
    {
        if ($existing = BookingSeriesMutation::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing->occurrence->load(['booking.facility', 'series']);
        }

        return DB::transaction(function () use ($actor, $occurrence, $changes, $idempotencyKey, $reason) {
            $occurrence = BookingSeriesOccurrence::lockForUpdate()->findOrFail($occurrence->id);
            $booking = Booking::lockForUpdate()->findOrFail($occurrence->booking_id);
            if (in_array($booking->attendance?->status, ['checked_in', 'late_checked_in'], true)) {
                abort_unless(app(BookingAccessService::class)->canManage($actor, $booking->facility) && filled($reason), 422, 'A checked-in booking requires an administrator override reason.');
            }
            abort_unless(in_array($booking->status, ['confirmed', 'pending_payment'], true) && $booking->starts_at->isFuture(), 422, 'Only future active occurrences may be amended.');
            $own = $actor->member_id && (int) $booking->member_id === (int) $actor->member_id;
            abort_unless($actor->active && ($own || app(BookingAccessService::class)->canManage($actor, $booking->facility)), 403);
            $facility = Facility::lockForUpdate()->findOrFail($changes['facility_id'] ?? $booking->facility_id);
            abort_unless($own ? app(BookingAccessService::class)->canView($actor, $facility) : app(BookingAccessService::class)->canManage($actor, $facility), 403);
            $start = Carbon::parse($changes['starts_at'] ?? $booking->starts_at);
            $end = Carbon::parse($changes['ends_at'] ?? $start->copy()->addMinutes($changes['duration_minutes'] ?? $booking->duration_minutes));
            $member = Member::findOrFail($booking->member_id);
            $lightingRequested = (bool) ($changes['lighting_requested'] ?? ($booking->lighting_snapshot['applies'] ?? false));
            $result = $this->evaluator->evaluate($actor, $member, $facility, $start, $end, (int) ($changes['guest_count'] ?? $booking->guest_count), null, $booking->id, $lightingRequested);
            abort_unless($result['eligible'], 422, $result['failures'][0]['member_message'] ?? 'The replacement slot is unavailable.');
            $old = $booking->only(['facility_id', 'starts_at', 'ends_at', 'duration_minutes', 'total_amount', 'allowance_amount']);
            app(BookingCheckInService::class)->revokeTokens($booking);
            app(BookingAccessGrantService::class)->revoke($booking, 'booking_amended');
            $difference = (int) $result['price']['total_amount'] - (int) $booking->total_amount;
            $this->financialDifference($actor, $booking, $occurrence, $difference, $result['price'], $reason);
            $booking->update([
                'facility_id' => $facility->id, 'sport_code_id' => $facility->sport_code_id,
                'starts_at' => $start, 'ends_at' => $end, 'duration_minutes' => $start->diffInMinutes($end),
                'guest_count' => (int) ($changes['guest_count'] ?? $booking->guest_count),
                'gross_amount' => $result['price']['gross_amount'], 'allowance_amount' => $result['price']['allowance_amount'],
                'total_amount' => $result['price']['total_amount'], 'rule_snapshot' => ['profile' => $result['profile'], 'policies' => $result['policies'], 'checks' => $result['checks']],
                'price_snapshot' => $result['price'], 'allowance_snapshot' => $result['allowance'], 'cancellation_snapshot' => $result['cancellation'],
                'lighting_snapshot' => $result['lighting'],
                'notes' => $changes['notes'] ?? $booking->notes,
            ]);
            app(LightingReservationService::class)->reschedule($booking, $result['lighting']);
            $this->replaceAllowance($booking, $result['allowance']);
            if (array_key_exists('participants', $changes)) {
                $booking->participants()->delete();
                foreach ($changes['participants'] as $participant) {
                    $booking->participants()->create($participant);
                }
            }
            $occurrence->update([
                'scheduled_date' => $start->toDateString(), 'starts_at' => $start, 'ends_at' => $end,
                'status' => 'confirmed', 'exception_type' => 'rescheduled', 'mutation_idempotency_key' => $idempotencyKey,
                'preview_snapshot' => [...$result, 'amendment_reason' => $reason],
            ]);
            if ($booking->attendance && ! in_array($booking->attendance->status, ['checked_in', 'late_checked_in'], true)) {
                $booking->attendance()->delete();
                app(BookingCheckInService::class)->ensure($booking->fresh());
            }
            app(BookingAccessGrantService::class)->ensure($booking->fresh('facility.accessZones'), app(BookingCheckInPolicyResolver::class)->resolve($booking->fresh(), $actor));
            BookingSeriesMutation::create([
                'booking_series_id' => $occurrence->booking_series_id, 'booking_series_occurrence_id' => $occurrence->id,
                'initiated_by_user_id' => $actor->id, 'mutation_type' => 'occurrence_amendment', 'scope' => 'occurrence',
                'idempotency_key' => $idempotencyKey, 'series_version' => $occurrence->series_version,
                'request_snapshot' => collect($changes)->except('participants')->all(),
                'result_snapshot' => ['booking_id' => $booking->id, 'facility_id' => $facility->id, 'starts_at' => $start, 'ends_at' => $end, 'price_difference' => $difference],
                'reason' => $reason,
            ]);
            Audit::record('booking_series.occurrence_amended', $occurrence, $old, [
                'booking_id' => $booking->id, 'facility_id' => $facility->id, 'starts_at' => $start, 'ends_at' => $end,
                'price_difference' => $difference, 'reason' => $reason, 'idempotency_key' => $idempotencyKey,
            ]);
            event(new \App\Events\BookingConfirmed($booking->id));

            return $occurrence->fresh(['booking.facility', 'series']);
        }, 3);
    }

    private function financialDifference(User $actor, Booking $booking, BookingSeriesOccurrence $occurrence, int $difference, array $price, ?string $reason): void
    {
        if ($difference === 0) {
            return;
        }
        if ($difference > 0) {
            $invoice = Invoice::firstOrCreate([
                'source_type' => BookingSeriesOccurrence::class, 'source_id' => $occurrence->id, 'billing_event' => 'amendment_increase',
            ], [
                'member_id' => $booking->member_id, 'currency' => $booking->currency, 'status' => 'draft',
                'subtotal_amount' => $difference, 'total_amount' => $difference, 'outstanding_amount' => $difference,
                'notes' => "Occurrence amendment {$booking->booking_reference}",
            ]);
            if ($invoice->wasRecentlyCreated) {
                $invoice->lines()->create([
                    'line_type' => 'facility_amendment', 'financial_owner_type' => 'sport_code', 'sport_code_id' => $booking->sport_code_id,
                    'description' => "Booking amendment {$booking->booking_reference}", 'quantity' => 1, 'unit_amount' => $difference,
                    'gross_amount' => $difference, 'net_amount' => $difference, 'total_amount' => $difference,
                    'source_type' => Booking::class, 'source_id' => $booking->id, 'snapshot_metadata' => $price,
                ]);
                app(InvoiceService::class)->issue($actor, $invoice);
            }

            return;
        }
        $amount = abs($difference);
        $invoice = $booking->invoice;
        abort_unless($invoice && $amount <= $booking->total_amount, 422, 'The amendment credit could not be reconciled.');
        $line = $invoice->lines()->where('source_type', Booking::class)->where('source_id', $booking->id)->first()
            ?? $invoice->lines()->first();
        $note = CreditNote::firstOrCreate(
            ['invoice_id' => $invoice->id, 'reason' => "Occurrence amendment {$occurrence->id}"],
            ['credit_note_number' => $this->numbers->next('credit_note'), 'member_id' => $booking->member_id, 'currency' => $booking->currency, 'status' => 'issued', 'total_amount' => $amount, 'reason' => "Occurrence amendment {$occurrence->id}", 'issued_by_user_id' => $actor->id, 'issued_on' => now()->toDateString()],
        );
        if ($note->wasRecentlyCreated) {
            $note->lines()->create(['invoice_line_id' => $line?->id, 'financial_owner_type' => $line?->financial_owner_type ?? 'sport_code', 'sport_code_id' => $line?->sport_code_id ?? $booking->sport_code_id, 'description' => "Occurrence amendment credit {$booking->booking_reference}", 'amount' => $amount]);
            FinancialTransaction::create([
                'member_id' => $booking->member_id, 'invoice_id' => $invoice->id, 'credit_note_id' => $note->id,
                'sport_code_id' => $line?->sport_code_id ?? $booking->sport_code_id, 'financial_owner_type' => $line?->financial_owner_type ?? 'sport_code',
                'transaction_type' => 'booking_amendment_credit', 'currency' => $booking->currency, 'amount' => -$amount,
                'occurred_on' => now()->toDateString(), 'reference' => $note->credit_note_number, 'reason' => $reason,
                'idempotency_key' => "occurrence-amendment-credit:{$occurrence->id}", 'created_by_user_id' => $actor->id,
            ]);
        }
    }

    private function replaceAllowance(Booking $booking, array $allowance): void
    {
        $booking->allowanceUsage()->delete();
        if (($allowance['minutes'] ?? 0) > 0) {
            BookingAllowanceUsage::create([
                'booking_allowance_policy_id' => $allowance['policy_id'], 'booking_id' => $booking->id, 'member_id' => $booking->member_id,
                'minutes' => $allowance['minutes'], 'value_cents' => $allowance['value_cents'],
                'period_starts_on' => $allowance['period_starts_on'], 'period_ends_on' => $allowance['period_ends_on'],
            ]);
        }
    }
}
