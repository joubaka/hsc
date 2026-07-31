<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAllowancePolicy;
use App\Models\BookingAllowanceUsage;
use App\Models\BookingHold;
use App\Models\BookingSetting;
use App\Models\Facility;
use App\Models\FacilityGroup;
use App\Models\FinancialSetting;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Payment;
use App\Models\SportCode;
use App\Models\User;
use App\Models\Venue;
use App\Services\BookingAvailabilityService;
use App\Services\BookingFinancialCompensationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingCancellationCompensationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-03 08:00:00');
        BookingSetting::create(['timezone' => 'Africa/Johannesburg', 'currency' => 'ZAR']);
        FinancialSetting::create([
            'default_currency' => 'ZAR', 'invoice_prefix' => 'INV', 'receipt_prefix' => 'REC',
            'credit_note_prefix' => 'CN', 'financial_year_start_month' => 1,
            'default_invoice_due_days' => 30, 'payment_reference_format' => '{member_number}',
            'joining_fee_owner_type' => 'sport_code',
        ]);
    }

    public function test_member_credit_is_exact_owner_preserving_and_idempotent_without_finance_permission(): void
    {
        [$user, $booking, $invoice] = $this->paidBooking(20000, [
            ['owner' => 'sport_code', 'amount' => 16000],
            ['owner' => 'central_hsc', 'amount' => 4000],
        ]);
        $service = app(BookingFinancialCompensationService::class);
        $allowancePolicy = BookingAllowancePolicy::create(['name' => 'Cancellation allowance', 'included_minutes' => 60, 'recurrence' => 'week', 'active' => true]);
        $allowance = BookingAllowanceUsage::create([
            'booking_allowance_policy_id' => $allowancePolicy->id, 'booking_id' => $booking->id,
            'member_id' => $booking->member_id, 'minutes' => 60, 'value_cents' => 20000,
            'period_starts_on' => '2026-08-03', 'period_ends_on' => '2026-08-09',
        ]);
        $first = $service->cancel($user, $booking, 'Plans changed', '11111111-1111-4111-8111-111111111111');
        $restoredAt = $allowance->fresh()->restored_at;
        $again = $service->cancel($user, $booking->fresh(), 'Retry', '22222222-2222-4222-8222-222222222222');

        $this->assertSame($first->id, $again->id);
        $this->assertSame('credited', $first->outcome_type);
        $this->assertSame(20000, $first->eligible_amount);
        $this->assertSame([16000, 4000], $first->creditNote->lines->pluck('amount')->all());
        $this->assertSame(20000, $first->creditNote->total_amount);
        $this->assertSame(0, $first->creditNote->applied_amount);
        $this->assertSame(20000, $invoice->fresh()->total_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseCount('booking_cancellation_compensations', 1);
        $this->assertDatabaseCount('credit_notes', 1);
        $this->assertDatabaseCount('credit_note_lines', 2);
        $this->assertNotNull($restoredAt);
        $this->assertSame($restoredAt->toDateTimeString(), $allowance->fresh()->restored_at->toDateTimeString());
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertEmpty(app(BookingAvailabilityService::class)->publicSchedule($booking->starts_at, $booking->ends_at)[0]['blocked_slots']);
    }

    public function test_partial_credit_rounding_is_deterministic_and_totals_match(): void
    {
        [$user, $booking] = $this->paidBooking(201, [
            ['owner' => 'sport_code', 'amount' => 101],
            ['owner' => 'central_hsc', 'amount' => 100],
        ], 50);
        $result = app(BookingFinancialCompensationService::class)->cancel(
            $user, $booking, 'Partial band', '33333333-3333-4333-8333-333333333333',
        );

        $this->assertSame('partially_credited', $result->outcome_type);
        $this->assertSame(100, $result->eligible_amount);
        $this->assertSame([50, 50], $result->creditNote->lines->pluck('amount')->all());
        $this->assertSame(100, $result->creditNote->lines->sum('amount'));
    }

    public function test_late_cancellation_releases_slot_without_compensation_and_audits_once(): void
    {
        [$user, $booking] = $this->paidBooking(12000, [['owner' => 'sport_code', 'amount' => 12000]]);
        $booking->update(['cancellation_snapshot' => [
            'cancellation_deadline' => now()->subMinute()->toIso8601String(), 'refund_percentage' => 100,
            'refund_method' => 'credit', 'version' => 7,
        ]]);
        $service = app(BookingFinancialCompensationService::class);
        $result = $service->cancel($user, $booking->fresh(), 'Late cancellation', '44444444-4444-4444-8444-444444444444');
        $service->cancel($user, $booking->fresh(), 'Retry', '55555555-5555-4555-8555-555555555555');

        $this->assertSame('no_compensation', $result->outcome_type);
        $this->assertSame(0, $result->eligible_amount);
        $this->assertDatabaseCount('credit_notes', 0);
        $this->assertDatabaseCount('booking_refund_requests', 0);
        $this->assertDatabaseCount('booking_cancellation_compensations', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_refund_entitlement_is_durable_and_finance_completion_is_idempotent(): void
    {
        [$user, $booking] = $this->paidBooking(15000, [
            ['owner' => 'sport_code', 'amount' => 12000],
            ['owner' => 'central_hsc', 'amount' => 3000],
        ], 100, 'refund');
        $service = app(BookingFinancialCompensationService::class);
        $compensation = $service->cancel($user, $booking, 'Refund requested', '66666666-6666-4666-8666-666666666666');
        $request = $compensation->refundRequest;

        $this->assertSame('refund_pending', $compensation->outcome_type);
        $this->assertSame('pending_finance_review', $request->status);
        $this->assertSame(15000, $request->entitlement_amount);
        $this->assertSame('cancelled', $booking->fresh()->status);
        $admin = User::factory()->create(['role' => 'hsc_admin', 'active' => true]);
        $processed = $service->completeRefund($admin, $request, 'BANK-REF-1');
        $again = $service->completeRefund($admin, $request->fresh(), 'BANK-REF-CHANGED');

        $this->assertSame($processed->id, $again->id);
        $this->assertSame('processed', $again->status);
        $this->assertSame('BANK-REF-1', $again->external_reference);
        $this->assertSame(15000, abs($booking->invoice->transactions()->where('transaction_type', 'booking_refund')->sum('amount')));
        $this->assertDatabaseCount('booking_refund_requests', 1);
        $this->assertDatabaseCount('booking_cancellation_compensations', 1);
        $this->assertSame(2, $booking->invoice->transactions()->where('transaction_type', 'booking_refund')->count());
    }

    public function test_member_cannot_cancel_another_members_booking_or_submit_financial_values(): void
    {
        [$user, $booking] = $this->paidBooking(10000, [['owner' => 'sport_code', 'amount' => 10000]]);
        $other = User::factory()->create([
            'member_id' => Member::create(['member_number' => 'OTHER', 'first_name' => 'Other', 'last_name' => 'Member', 'status' => 'active'])->id,
            'role' => 'member', 'active' => true, 'api_token_hash' => hash('sha256', 'other-token'),
        ]);
        $this->withToken('other-token')->getJson("/api/bookings/{$booking->id}/cancellation-preview")->assertForbidden()->assertJsonMissing(['amount_paid']);
        $this->withToken('other-token')->postJson("/api/bookings/{$booking->id}/cancel", [
            'reason' => 'Not mine', 'idempotency_key' => '77777777-7777-4777-8777-777777777777',
            'eligible_amount' => 999999, 'invoice_id' => $booking->invoice_id,
        ])->assertForbidden();
        $this->assertDatabaseCount('booking_cancellation_compensations', 0);
        $this->assertNotSame($user->id, $other->id);
    }

    public function test_unpaid_booking_voids_invoice_without_paid_credit(): void
    {
        [$user, $booking, $invoice] = $this->paidBooking(9000, [['owner' => 'sport_code', 'amount' => 9000]], 100, 'credit', false);
        $result = app(BookingFinancialCompensationService::class)->cancel(
            $user, $booking, 'Cancel unpaid', '88888888-8888-4888-8888-888888888888',
        );

        $this->assertSame('invoice_cancelled', $result->outcome_type);
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertDatabaseCount('credit_notes', 0);
        $this->assertDatabaseCount('booking_refund_requests', 0);
    }

    public function test_read_only_disabled_cross_sport_and_member_finance_access_are_blocked(): void
    {
        [$memberUser, $booking] = $this->paidBooking(10000, [['owner' => 'sport_code', 'amount' => 10000]], 100, 'refund');
        $tennis = $booking->facility->sport_code_id;
        $padel = SportCode::create(['name' => 'Padel', 'slug' => 'padel']);
        $readOnly = User::factory()->create(['role' => 'sport_viewer', 'active' => true, 'api_token_hash' => hash('sha256', 'read-only')]);
        $readOnly->sportCodes()->attach($tennis, ['role' => 'viewer']);
        $crossSport = User::factory()->create(['role' => 'sport_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'cross-sport')]);
        $crossSport->sportCodes()->attach($padel->id, ['role' => 'administrator']);
        User::factory()->create(['role' => 'hsc_admin', 'active' => false, 'api_token_hash' => hash('sha256', 'disabled')]);

        foreach (['read-only', 'cross-sport'] as $token) {
            $this->withToken($token)->postJson("/api/bookings/{$booking->id}/cancel", [
                'reason' => 'Forbidden cancellation', 'idempotency_key' => (string) str()->uuid(),
            ])->assertForbidden()->assertJsonMissing(['eligible_amount', 'invoice_id', 'financial_owner_breakdown']);
        }
        $this->withToken('disabled')->postJson("/api/bookings/{$booking->id}/cancel", [
            'reason' => 'Forbidden cancellation', 'idempotency_key' => (string) str()->uuid(),
        ])->assertUnauthorized()->assertJsonMissing(['eligible_amount', 'invoice_id', 'financial_owner_breakdown']);
        $memberUser->update(['api_token_hash' => hash('sha256', 'member-token')]);
        $this->withToken('member-token')->getJson('/api/booking/refund-requests')->assertForbidden();
        $this->assertDatabaseCount('booking_cancellation_compensations', 0);
    }

    public function test_expired_hold_command_is_idempotent_and_availability_already_ignores_it(): void
    {
        [$user, $booking] = $this->paidBooking(0, [['owner' => 'sport_code', 'amount' => 0]], 0, 'credit', false);
        $booking->update(['status' => 'cancelled']);
        $hold = BookingHold::create([
            'hold_reference' => '99999999-9999-4999-8999-999999999999', 'facility_id' => $booking->facility_id,
            'user_id' => $user->id, 'member_id' => $booking->member_id, 'starts_at' => '2026-08-05 10:00',
            'ends_at' => '2026-08-05 11:00', 'expires_at' => now()->subMinute(), 'status' => 'active',
            'rule_snapshot' => [], 'price_snapshot' => [], 'cancellation_snapshot' => [],
            'idempotency_key' => '99999999-9999-4999-8999-999999999998',
        ]);
        $schedule = app(BookingAvailabilityService::class)->publicSchedule(Carbon::parse('2026-08-05 09:00'), Carbon::parse('2026-08-05 12:00'));
        $this->assertEmpty($schedule[0]['blocked_slots']);
        $this->artisan('bookings:expire-holds')->expectsOutput('Booking holds examined: 1; expired: 1; failed: 0')->assertSuccessful();
        $this->artisan('bookings:expire-holds')->expectsOutput('Booking holds examined: 0; expired: 0; failed: 0')->assertSuccessful();
        $this->assertSame('expired', $hold->fresh()->status);
    }

    private function paidBooking(int $amount, array $owners, int $percentage = 100, string $method = 'credit', bool $paid = true): array
    {
        $sport = SportCode::create(['name' => 'Tennis', 'slug' => 'tennis']);
        $member = Member::create(['member_number' => 'HSC-C001', 'first_name' => 'Cancel', 'last_name' => 'Member', 'status' => 'active']);
        $user = User::factory()->create(['member_id' => $member->id, 'role' => 'member', 'active' => true]);
        $venue = Venue::create(['name' => 'HSC', 'code' => 'HSC']);
        $group = FacilityGroup::create(['sport_code_id' => $sport->id, 'name' => 'Tennis', 'code' => 'TENNIS']);
        $facility = Facility::create([
            'venue_id' => $venue->id, 'sport_code_id' => $sport->id, 'facility_group_id' => $group->id,
            'name' => 'Cancellation Court', 'code' => 'CAN-1', 'facility_type' => 'tennis_court',
            'publicly_visible' => true, 'bookable' => true, 'active' => true,
        ]);
        $booking = Booking::create([
            'booking_reference' => 'BKG-CANCEL-'.str()->upper(str()->random(6)), 'facility_id' => $facility->id,
            'sport_code_id' => $sport->id, 'member_id' => $member->id, 'created_by_user_id' => $user->id,
            'status' => $paid ? 'confirmed' : 'pending_payment', 'payment_status' => $paid ? 'paid' : 'unpaid',
            'starts_at' => '2026-08-05 10:00', 'ends_at' => '2026-08-05 11:00', 'duration_minutes' => 60,
            'profile' => 'adult_member', 'currency' => 'ZAR', 'gross_amount' => $amount, 'total_amount' => $amount,
            'rule_snapshot' => [], 'price_snapshot' => ['total_amount' => $amount], 'allowance_snapshot' => [],
            'cancellation_snapshot' => [
                'cancellation_deadline' => '2026-08-04 10:00:00', 'refund_percentage' => $percentage,
                'refund_method' => $method, 'version' => 3, 'policy_ids' => [17], 'policy_band' => 'test_band',
            ], 'confirmation_key' => (string) str()->uuid(),
        ]);
        $invoice = Invoice::create([
            'member_id' => $member->id, 'currency' => 'ZAR', 'status' => $paid ? 'paid' : 'issued',
            'issued_on' => now(), 'due_on' => now(), 'subtotal_amount' => $amount, 'total_amount' => $amount,
            'paid_amount' => $paid ? $amount : 0, 'outstanding_amount' => $paid ? 0 : $amount,
            'source_type' => Booking::class, 'source_id' => $booking->id, 'billing_event' => 'booking',
        ]);
        foreach ($owners as $index => $owner) {
            $invoice->lines()->create([
                'line_type' => 'court_rental', 'financial_owner_type' => $owner['owner'],
                'sport_code_id' => $owner['owner'] === 'sport_code' ? $sport->id : null,
                'description' => "Charge {$index}", 'quantity' => 1, 'unit_amount' => $owner['amount'],
                'gross_amount' => $owner['amount'], 'net_amount' => $owner['amount'], 'total_amount' => $owner['amount'],
            ]);
        }
        $booking->update(['invoice_id' => $invoice->id]);
        if ($paid) {
            $payment = Payment::create([
                'payment_reference' => 'PAY-'.str()->upper(str()->random(8)), 'member_id' => $member->id,
                'payer_name' => $member->display_name, 'currency' => 'ZAR', 'amount' => $amount,
                'received_on' => now(), 'payment_method' => 'eft', 'status' => 'allocated',
                'allocated_amount' => $amount, 'unallocated_amount' => 0, 'recorded_by_user_id' => $user->id,
                'confirmed_by_user_id' => $user->id, 'confirmed_at' => now(), 'source' => 'manual',
            ]);
            $invoice->allocations()->create([
                'payment_id' => $payment->id, 'amount' => $amount, 'idempotency_key' => (string) str()->uuid(),
                'allocated_by_user_id' => $user->id, 'allocated_at' => now(),
            ]);
        }

        return [$user, $booking->fresh(['facility', 'invoice.lines']), $invoice];
    }
}
