<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingAllowanceUsage;
use App\Models\BookingHold;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(private BookingRuleEvaluator $evaluator, private BookingBillingService $billing) {}

    public function createHold(User $user, Facility $facility, ?Member $member, array $data): BookingHold
    {
        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        if ($existing = BookingHold::where('idempotency_key', $data['idempotency_key'])->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $facility, $member, $data, $start, $end) {
            $facility = Facility::lockForUpdate()->findOrFail($facility->id);
            $profile = $member ? null : ['profile' => 'public_visitor', 'member_id' => null, 'age' => null, 'sport_membership_id' => null, 'membership_category_id' => null, 'failures' => [], 'eligible' => true];
            $result = $this->evaluator->evaluate($user, $member, $facility, $start, $end, (int) ($data['guest_count'] ?? 0), null, null, (bool) ($data['lighting_requested'] ?? false), $profile);
            abort_unless($result['eligible'], 422, $result['failures'][0]['member_message'] ?? 'This booking is not eligible.');
            $settings = \App\Models\BookingSetting::firstOrCreate([]);
            $hold = BookingHold::create([
                'hold_reference' => (string) Str::uuid(), 'facility_id' => $facility->id, 'user_id' => $user->id,
                'member_id' => $member?->id, 'booked_for_name' => $data['booked_for_name'] ?? null, 'starts_at' => $start, 'ends_at' => $end,
                'expires_at' => now()->addMinutes($settings->hold_minutes), 'status' => 'active',
                'rule_snapshot' => ['profile' => $result['profile'], 'policies' => $result['policies'], 'checks' => $result['checks']],
                'price_snapshot' => $result['price'], 'allowance_snapshot' => $result['allowance'],
                'cancellation_snapshot' => $result['cancellation'], 'idempotency_key' => $data['idempotency_key'],
                'lighting_snapshot' => $result['lighting'],
            ]);
            Audit::record('booking.hold_created', $hold, [], ['facility_id' => $facility->id, 'member_id' => $member?->id, 'booked_for_name' => $hold->booked_for_name, 'starts_at' => $start, 'ends_at' => $end, 'amount' => $result['price']['total_amount']]);
            event(new \App\Events\BookingHoldCreated($hold->id));

            return $hold->fresh('facility');
        });
    }

    public function confirm(User $user, BookingHold $hold, string $confirmationKey, array $participants = [], string $billingStrategy = 'invoice_each_occurrence'): Booking
    {
        if ($existing = Booking::where('confirmation_key', $confirmationKey)->orWhere('booking_hold_id', $hold->id)->first()) {
            return $existing->load(['facility', 'invoice']);
        }

        return DB::transaction(function () use ($user, $hold, $confirmationKey, $participants, $billingStrategy) {
            $hold = BookingHold::lockForUpdate()->findOrFail($hold->id);
            abort_unless($hold->user_id === $user->id || $user->isCentralAdministrator(), 403);
            abort_unless($hold->status === 'active' && $hold->expires_at->isFuture(), 422, 'This booking hold has expired.');
            $facility = Facility::lockForUpdate()->findOrFail($hold->facility_id);
            $member = $hold->member_id ? Member::findOrFail($hold->member_id) : null;
            $profile = $member ? null : ['profile' => 'public_visitor', 'member_id' => null, 'age' => null, 'sport_membership_id' => null, 'membership_category_id' => null, 'failures' => [], 'eligible' => true];
            $result = $this->evaluator->evaluate($user, $member, $facility, $hold->starts_at, $hold->ends_at, (int) ($hold->price_snapshot['guest_count'] ?? 0), $hold->id, null, (bool) ($hold->lighting_snapshot['applies'] ?? false), $profile);
            abort_unless($result['eligible'], 422, $result['failures'][0]['member_message'] ?? 'This booking can no longer be confirmed.');
            if ($billingStrategy === 'one_invoice_for_series' && app(BookingAccessService::class)->canManage($user, $facility)) {
                $result['price'] = $hold->price_snapshot;
                $result['allowance'] = $hold->allowance_snapshot;
            }
            abort_unless($result['price']['total_amount'] === $hold->price_snapshot['total_amount'], 422, 'The booking price changed. Please create a new hold.');
            abort_if($billingStrategy === 'allowance_covered' && ($result['allowance']['minutes'] ?? 0) < $hold->starts_at->diffInMinutes($hold->ends_at), 422, 'This occurrence is not fully covered by allowance.');
            if ($billingStrategy === 'no_charge') {
                $lightingAmount = (int) ($result['price']['lighting_amount'] ?? 0);
                $courtAndGuestAmount = max(0, (int) $result['price']['gross_amount'] - $lightingAmount);
                $result['price']['allowance_amount'] = $courtAndGuestAmount;
                $result['price']['total_amount'] = $lightingAmount;
                $result['allowance']['minutes'] = 0;
                $result['allowance']['value_cents'] = 0;
            }
            $zero = $result['price']['total_amount'] === 0;
            $booking = Booking::create([
                'booking_reference' => 'BKG-'.strtoupper(Str::random(10)), 'facility_id' => $facility->id,
                'sport_code_id' => $facility->sport_code_id, 'member_id' => $member?->id, 'booked_for_name' => $hold->booked_for_name, 'created_by_user_id' => $user->id,
                'booking_hold_id' => $hold->id, 'status' => $zero ? 'confirmed' : 'pending_payment',
                'payment_status' => $zero ? ($result['allowance']['minutes'] ? 'covered_by_allowance' : 'not_required') : ($billingStrategy === 'one_invoice_for_series' ? 'series_invoice_pending' : 'unpaid'),
                'starts_at' => $hold->starts_at, 'ends_at' => $hold->ends_at, 'duration_minutes' => $hold->starts_at->diffInMinutes($hold->ends_at),
                'guest_count' => (int) ($hold->price_snapshot['guest_count'] ?? 0), 'profile' => $result['profile']['profile'],
                'currency' => $result['price']['currency'], 'gross_amount' => $result['price']['gross_amount'],
                'allowance_amount' => $result['price']['allowance_amount'], 'total_amount' => $result['price']['total_amount'],
                'rule_snapshot' => $hold->rule_snapshot, 'price_snapshot' => $result['price'],
                'allowance_snapshot' => $result['allowance'], 'cancellation_snapshot' => $result['cancellation'],
                'lighting_snapshot' => $hold->lighting_snapshot,
                'confirmation_key' => $confirmationKey,
            ]);
            foreach ($participants as $participant) {
                $booking->participants()->create($participant);
            }
            if ($member && ($result['allowance']['minutes'] ?? 0) > 0) {
                BookingAllowanceUsage::create([
                    'booking_allowance_policy_id' => $result['allowance']['policy_id'], 'booking_id' => $booking->id,
                    'member_id' => $member->id, 'minutes' => $result['allowance']['minutes'], 'value_cents' => $result['allowance']['value_cents'],
                    'period_starts_on' => $result['allowance']['period_starts_on'], 'period_ends_on' => $result['allowance']['period_ends_on'],
                ]);
            }
            $hold->update(['status' => 'confirmed']);
            if ($billingStrategy !== 'one_invoice_for_series' && $booking->total_amount > 0) {
                $this->billing->createInvoice($user, $booking);
            }
            Audit::record('booking.confirmed', $booking, [], ['booking_reference' => $booking->booking_reference, 'facility_id' => $facility->id, 'amount' => $booking->total_amount, 'currency' => $booking->currency]);
            event(new \App\Events\BookingConfirmed($booking->id));

            if ($hold->lighting_snapshot['applies'] ?? false) {
                app(LightingReservationService::class)->reserve($booking, $hold->lighting_snapshot);
            }
            app(BookingCheckInService::class)->ensure($booking);

            return $booking->fresh(['facility', 'invoice', 'participants']);
        }, 3);
    }

    public function recognizePayment(User $user, Booking $booking): Booking
    {
        abort_unless($booking->created_by_user_id === $user->id || $user->isCentralAdministrator(), 403);
        abort_unless($booking->invoice && $booking->invoice->outstanding_amount === 0, 422, 'The booking invoice is not fully paid.');
        $booking->update(['status' => 'confirmed', 'payment_status' => 'paid']);
        app(LightingReservationService::class)->markPaid($booking);
        Audit::record('booking.payment_linked', $booking, [], ['invoice_id' => $booking->invoice_id]);

        return $booking->fresh(['facility', 'invoice']);
    }

    public function syncInvoicePayment(Invoice $invoice): void
    {
        if ($invoice->source_type !== Booking::class) {
            return;
        }

        $paid = $invoice->status === 'paid' && (int) $invoice->outstanding_amount === 0;

        foreach (Booking::where('invoice_id', $invoice->id)->get() as $booking) {
            if ($paid) {
                if ($booking->status === 'pending_payment' || $booking->payment_status !== 'paid') {
                    $booking->update(['status' => 'confirmed', 'payment_status' => 'paid']);
                    app(LightingReservationService::class)->markPaid($booking);
                    Audit::record('booking.payment_linked', $booking, [], ['invoice_id' => $invoice->id]);
                }

                continue;
            }

            if ($booking->status === 'confirmed' && $booking->payment_status === 'paid' && $booking->starts_at->isFuture()) {
                $booking->update(['status' => 'pending_payment', 'payment_status' => 'unpaid']);
                app(LightingReservationService::class)->markUnpaid($booking);
                Audit::record('booking.payment_unlinked', $booking, [], ['invoice_id' => $invoice->id]);
            }
        }
    }
}
