<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingAllowanceUsage;
use App\Models\BookingHold;
use App\Models\Facility;
use App\Models\Member;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(private BookingRuleEvaluator $evaluator, private BookingBillingService $billing) {}

    public function createHold(User $user, Facility $facility, Member $member, array $data): BookingHold
    {
        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        if ($existing = BookingHold::where('idempotency_key', $data['idempotency_key'])->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $facility, $member, $data, $start, $end) {
            $facility = Facility::lockForUpdate()->findOrFail($facility->id);
            $result = $this->evaluator->evaluate($user, $member, $facility, $start, $end, (int) ($data['guest_count'] ?? 0));
            abort_unless($result['eligible'], 422, $result['failures'][0]['member_message'] ?? 'This booking is not eligible.');
            $settings = \App\Models\BookingSetting::firstOrCreate([]);
            $hold = BookingHold::create([
                'hold_reference' => (string) Str::uuid(), 'facility_id' => $facility->id, 'user_id' => $user->id,
                'member_id' => $member->id, 'starts_at' => $start, 'ends_at' => $end,
                'expires_at' => now()->addMinutes($settings->hold_minutes), 'status' => 'active',
                'rule_snapshot' => ['profile' => $result['profile'], 'policies' => $result['policies'], 'checks' => $result['checks']],
                'price_snapshot' => $result['price'], 'allowance_snapshot' => $result['allowance'],
                'cancellation_snapshot' => $result['cancellation'], 'idempotency_key' => $data['idempotency_key'],
            ]);
            Audit::record('booking.hold_created', $hold, [], ['facility_id' => $facility->id, 'member_id' => $member->id, 'starts_at' => $start, 'ends_at' => $end, 'amount' => $result['price']['total_amount']]);
            event(new \App\Events\BookingHoldCreated($hold->id));

            return $hold->fresh('facility');
        });
    }

    public function confirm(User $user, BookingHold $hold, string $confirmationKey, array $participants = []): Booking
    {
        if ($existing = Booking::where('confirmation_key', $confirmationKey)->orWhere('booking_hold_id', $hold->id)->first()) {
            return $existing->load(['facility', 'invoice']);
        }

        return DB::transaction(function () use ($user, $hold, $confirmationKey, $participants) {
            $hold = BookingHold::lockForUpdate()->findOrFail($hold->id);
            abort_unless($hold->user_id === $user->id || $user->isCentralAdministrator(), 403);
            abort_unless($hold->status === 'active' && $hold->expires_at->isFuture(), 422, 'This booking hold has expired.');
            $facility = Facility::lockForUpdate()->findOrFail($hold->facility_id);
            $member = Member::findOrFail($hold->member_id);
            $result = $this->evaluator->evaluate($user, $member, $facility, $hold->starts_at, $hold->ends_at, (int) ($hold->price_snapshot['guest_count'] ?? 0), $hold->id);
            abort_unless($result['eligible'], 422, $result['failures'][0]['member_message'] ?? 'This booking can no longer be confirmed.');
            abort_unless($result['price']['total_amount'] === $hold->price_snapshot['total_amount'], 422, 'The booking price changed. Please create a new hold.');
            $zero = $result['price']['total_amount'] === 0;
            $booking = Booking::create([
                'booking_reference' => 'BKG-'.strtoupper(Str::random(10)), 'facility_id' => $facility->id,
                'sport_code_id' => $facility->sport_code_id, 'member_id' => $member->id, 'created_by_user_id' => $user->id,
                'booking_hold_id' => $hold->id, 'status' => $zero ? 'confirmed' : 'pending_payment',
                'payment_status' => $zero ? ($result['allowance']['minutes'] ? 'covered_by_allowance' : 'not_required') : 'unpaid',
                'starts_at' => $hold->starts_at, 'ends_at' => $hold->ends_at, 'duration_minutes' => $hold->starts_at->diffInMinutes($hold->ends_at),
                'guest_count' => (int) ($hold->price_snapshot['guest_count'] ?? 0), 'profile' => $result['profile']['profile'],
                'currency' => $result['price']['currency'], 'gross_amount' => $result['price']['gross_amount'],
                'allowance_amount' => $result['price']['allowance_amount'], 'total_amount' => $result['price']['total_amount'],
                'rule_snapshot' => $hold->rule_snapshot, 'price_snapshot' => $result['price'],
                'allowance_snapshot' => $result['allowance'], 'cancellation_snapshot' => $result['cancellation'],
                'confirmation_key' => $confirmationKey,
            ]);
            foreach ($participants as $participant) {
                $booking->participants()->create($participant);
            }
            if (($result['allowance']['minutes'] ?? 0) > 0) {
                BookingAllowanceUsage::create([
                    'booking_allowance_policy_id' => $result['allowance']['policy_id'], 'booking_id' => $booking->id,
                    'member_id' => $member->id, 'minutes' => $result['allowance']['minutes'], 'value_cents' => $result['allowance']['value_cents'],
                    'period_starts_on' => $result['allowance']['period_starts_on'], 'period_ends_on' => $result['allowance']['period_ends_on'],
                ]);
            }
            $hold->update(['status' => 'confirmed']);
            $this->billing->createInvoice($user, $booking);
            Audit::record('booking.confirmed', $booking, [], ['booking_reference' => $booking->booking_reference, 'facility_id' => $facility->id, 'amount' => $booking->total_amount, 'currency' => $booking->currency]);
            event(new \App\Events\BookingConfirmed($booking->id));

            return $booking->fresh(['facility', 'invoice', 'participants']);
        }, 3);
    }

    public function recognizePayment(User $user, Booking $booking): Booking
    {
        abort_unless($booking->created_by_user_id === $user->id || $user->isCentralAdministrator(), 403);
        abort_unless($booking->invoice && $booking->invoice->outstanding_amount === 0, 422, 'The booking invoice is not fully paid.');
        $booking->update(['status' => 'confirmed', 'payment_status' => 'paid']);
        Audit::record('booking.payment_linked', $booking, [], ['invoice_id' => $booking->invoice_id]);

        return $booking->fresh(['facility', 'invoice']);
    }
}
