<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingAllowancePolicy;
use App\Models\BookingPricingRule;
use App\Models\BookingSetting;
use App\Models\Facility;
use App\Models\FacilityGroup;
use App\Models\FinancialSetting;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipPeriod;
use App\Models\SportCode;
use App\Models\SportMembership;
use App\Models\User;
use App\Models\Venue;
use App\Services\BookingAvailabilityService;
use App\Services\BookingProfileResolver;
use App\Services\BookingRuleEvaluator;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-03 08:00:00');
        BookingSetting::create(['timezone' => 'Africa/Johannesburg', 'currency' => 'ZAR', 'hold_minutes' => 10, 'default_hourly_rate_cents' => 12000]);
        FinancialSetting::create(['default_currency' => 'ZAR', 'invoice_prefix' => 'INV', 'receipt_prefix' => 'REC', 'credit_note_prefix' => 'CN', 'financial_year_start_month' => 1, 'default_invoice_due_days' => 30, 'payment_reference_format' => '{member_number}', 'joining_fee_owner_type' => 'sport_code']);
    }

    public function test_profile_resolves_correct_sport_and_rejects_wrong_sport(): void
    {
        [$user, $member, $facility] = $this->context();
        $profile = app(BookingProfileResolver::class)->resolve($user, $member, $facility, Carbon::parse('2026-08-04 10:00'));
        $this->assertTrue($profile['eligible']);
        $this->assertSame('adult_member', $profile['profile']);

        $padel = SportCode::create(['name' => 'Padel', 'slug' => 'padel']);
        $facility->update(['sport_code_id' => $padel->id]);
        $wrong = app(BookingProfileResolver::class)->resolve($user, $member, $facility->fresh('sportCode'), Carbon::parse('2026-08-04 10:00'));
        $this->assertFalse($wrong['eligible']);
        $this->assertSame('sport_membership_required', $wrong['failures'][0]['code']);
    }

    public function test_evaluator_uses_server_pricing_allowance_and_explanations(): void
    {
        [$user, $member, $facility, $group, $category] = $this->context();
        BookingPricingRule::create(['name' => 'Court hourly', 'scope_type' => 'facility', 'scope_id' => $facility->id, 'pricing_method' => 'hourly', 'amount_cents' => 12000, 'guest_fee_cents' => 5000, 'priority' => 10, 'active' => true]);
        BookingAllowancePolicy::create(['name' => 'Included half hour', 'sport_code_id' => $facility->sport_code_id, 'facility_group_id' => $group->id, 'profile' => 'adult_member', 'membership_category_id' => $category->id, 'included_minutes' => 30, 'recurrence' => 'week', 'active' => true]);
        $result = app(BookingRuleEvaluator::class)->evaluate($user, $member, $facility, Carbon::parse('2026-08-04 10:00'), Carbon::parse('2026-08-04 11:00'), 1);

        $this->assertTrue($result['eligible']);
        $this->assertSame(17000, $result['price']['gross_amount']);
        $this->assertSame(12000, $result['price']['allowance_amount']);
        $this->assertSame(5000, $result['price']['total_amount']);
        $this->assertSame(5000, $result['price']['court_amount']); // Guest fee only; member court time is fully waived.
        $this->assertSame(3500, $result['price']['member_court_waiver_amount']);
        $this->assertNotEmpty($result['checks']);
        $this->assertArrayHasKey('cancellation_deadline', $result['cancellation']);
    }

    public function test_opening_hours_are_evaluated_in_the_venue_timezone(): void
    {
        [$user, $member, $facility] = $this->context();

        $result = app(BookingRuleEvaluator::class)->evaluate(
            $user,
            $member,
            $facility,
            Carbon::parse('2026-08-04T05:30:00Z'),
            Carbon::parse('2026-08-04T07:30:00Z'),
        );

        $openingHours = collect($result['checks'])->firstWhere('code', 'opening_hours');

        $this->assertTrue($openingHours['passed']);
        $this->assertTrue($result['eligible']);
    }

    public function test_holds_block_conflicts_but_expired_holds_do_not(): void
    {
        [$user, $member, $facility] = $this->context();
        $service = app(BookingService::class);
        $first = $service->createHold($user, $facility, $member, $this->slot('2026-08-04 10:00', '2026-08-04 11:00', '11111111-1111-4111-8111-111111111111'));
        $this->assertSame('active', $first->status);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->createHold($user, $facility, $member, $this->slot('2026-08-04 10:30', '2026-08-04 11:30', '22222222-2222-4222-8222-222222222222'));
    }

    public function test_expired_hold_adjacent_slot_and_different_facility_are_available(): void
    {
        [$user, $member, $facility] = $this->context();
        $service = app(BookingService::class);
        $hold = $service->createHold($user, $facility, $member, $this->slot('2026-08-04 10:00', '2026-08-04 11:00', '33333333-3333-4333-8333-333333333333'));
        $hold->update(['expires_at' => now()->subMinute()]);
        $replacement = $service->createHold($user, $facility, $member, $this->slot('2026-08-04 10:30', '2026-08-04 11:30', '44444444-4444-4444-8444-444444444444'));
        $adjacent = $service->createHold($user, $facility, $member, $this->slot('2026-08-04 11:30', '2026-08-04 12:30', '55555555-5555-4555-8555-555555555555'));
        $other = $facility->replicate()->fill(['name' => 'Court 2', 'code' => 'TEN-2'])->save();

        $this->assertNotNull($replacement->id);
        $this->assertNotNull($adjacent->id);
        $this->assertDatabaseHas('facilities', ['code' => 'TEN-2']);
    }

    public function test_member_court_confirmation_is_idempotent_conflict_safe_and_free_without_lighting(): void
    {
        [$user, $member, $facility] = $this->context();
        BookingPricingRule::create(['name' => 'Paid rate', 'scope_type' => 'facility', 'scope_id' => $facility->id, 'pricing_method' => 'fixed', 'amount_cents' => 15000, 'priority' => 10, 'active' => true]);
        $service = app(BookingService::class);
        $hold = $service->createHold($user, $facility, $member, $this->slot('2026-08-04 13:00', '2026-08-04 14:00', '66666666-6666-4666-8666-666666666666'));
        $booking = $service->confirm($user, $hold, '77777777-7777-4777-8777-777777777777');
        $again = $service->confirm($user, $hold->fresh(), '77777777-7777-4777-8777-777777777777');

        $this->assertSame($booking->id, $again->id);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(0, $booking->total_amount);
        $this->assertNull($booking->invoice);
        $this->assertSame(1, Booking::count());
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_administrator_can_confirm_a_complimentary_member_booking_without_an_invoice(): void
    {
        [, $member, $facility] = $this->context();
        BookingPricingRule::create(['name' => 'Admin waived rate', 'scope_type' => 'facility', 'scope_id' => $facility->id, 'pricing_method' => 'fixed', 'amount_cents' => 15000, 'priority' => 10, 'active' => true]);
        $admin = User::factory()->create(['role' => 'hsc_super_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'complimentary-admin')]);
        $hold = app(BookingService::class)->createHold($admin, $facility, $member, $this->slot('2026-08-04 15:00', '2026-08-04 16:00', '99999999-9999-4999-8999-999999999999'));

        $bookingId = $this->withToken('complimentary-admin')->postJson("/api/booking/holds/{$hold->id}/confirm", [
            'confirmation_key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'participants' => [],
            'billing_strategy' => 'no_charge',
        ])->assertCreated()->json('id');

        $booking = Booking::findOrFail($bookingId);
        $this->assertSame(0, $booking->total_amount);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('not_required', $booking->payment_status);
        $this->assertNull($booking->invoice_id);
    }

    public function test_public_availability_is_privacy_safe(): void
    {
        [$user, $member, $facility] = $this->context();
        Booking::create([
            'booking_reference' => 'BKG-PRIVATE', 'facility_id' => $facility->id, 'sport_code_id' => $facility->sport_code_id,
            'member_id' => $member->id, 'created_by_user_id' => $user->id, 'status' => 'confirmed', 'payment_status' => 'not_required',
            'starts_at' => '2026-08-04 10:00', 'ends_at' => '2026-08-04 11:00', 'duration_minutes' => 60, 'profile' => 'adult_member',
            'currency' => 'ZAR', 'rule_snapshot' => [], 'price_snapshot' => [], 'cancellation_snapshot' => [], 'confirmation_key' => '88888888-8888-4888-8888-888888888888',
        ]);
        $payload = app(BookingAvailabilityService::class)->publicSchedule(Carbon::parse('2026-08-04'), Carbon::parse('2026-08-05'));
        $json = json_encode($payload);
        $this->assertStringContainsString('unavailable', $json);
        $this->assertStringNotContainsString($member->first_name, $json);
        $this->assertStringNotContainsString('BKG-PRIVATE', $json);
        $this->assertStringNotContainsString('member_id', $json);
    }

    public function test_opening_overlap_and_cross_code_facility_mutation_are_blocked(): void
    {
        [$user, $member, $facility] = $this->context();
        $token = 'sport-token';
        $admin = User::factory()->create(['role' => 'sport_admin', 'active' => true, 'api_token_hash' => hash('sha256', $token)]);
        $padel = SportCode::create(['name' => 'Padel', 'slug' => 'padel']);
        $admin->sportCodes()->attach($padel->id, ['role' => 'administrator']);
        $this->withToken($token)->postJson("/api/booking/facilities/{$facility->id}/opening-hours", [
            'day_of_week' => 2, 'opens_at' => '08:00', 'closes_at' => '10:00', 'active' => true,
        ])->assertForbidden();
        $central = User::factory()->create(['role' => 'hsc_super_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'central')]);
        $this->withToken('central')->postJson("/api/booking/facilities/{$facility->id}/opening-hours", [
            'day_of_week' => 2, 'opens_at' => '08:00', 'closes_at' => '10:00', 'active' => true,
        ])->assertUnprocessable()->assertJsonPath('message', 'Opening-hour windows may not overlap.');
    }

    private function context(): array
    {
        $sport = SportCode::firstOrCreate(['slug' => 'tennis'], ['name' => 'Tennis']);
        $category = MembershipCategory::firstOrCreate(['sport_code_id' => $sport->id, 'name' => 'Full'], ['billing_period' => 'annual', 'code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'active' => true]);
        $period = MembershipPeriod::factory()->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        $member = Member::create(['member_number' => 'HSC-B001', 'first_name' => 'Booking', 'last_name' => 'Member', 'date_of_birth' => '1990-01-01', 'status' => 'active']);
        SportMembership::create(['member_id' => $member->id, 'sport_code_id' => $sport->id, 'membership_category_id' => $category->id, 'membership_period_id' => $period->id, 'status' => 'active', 'role' => 'member', 'joined_or_renewed_on' => '2026-01-01', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'sport_code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'total_cents' => 120000, 'billing_frequency' => 'annual']);
        $user = User::factory()->create(['member_id' => $member->id, 'role' => 'member', 'active' => true]);
        $venue = Venue::create(['name' => 'HSC', 'code' => 'HSC', 'timezone' => 'Africa/Johannesburg']);
        $group = FacilityGroup::create(['sport_code_id' => $sport->id, 'name' => 'Tennis', 'code' => 'TENNIS']);
        $facility = Facility::create(['venue_id' => $venue->id, 'sport_code_id' => $sport->id, 'facility_group_id' => $group->id, 'name' => 'Court 1', 'code' => 'TEN-1', 'facility_type' => 'tennis_court', 'publicly_visible' => true, 'bookable' => true, 'active' => true, 'default_duration_minutes' => 60, 'start_interval_minutes' => 30]);
        foreach (range(0, 6) as $day) {
            $facility->openingHours()->create(['day_of_week' => $day, 'opens_at' => '07:00', 'closes_at' => '21:00', 'active' => true]);
        }
        $facility->load(['venue', 'sportCode', 'openingHours', 'group']);

        return [$user, $member, $facility, $group, $category];
    }

    private function slot(string $start, string $end, string $key): array
    {
        return ['starts_at' => $start, 'ends_at' => $end, 'guest_count' => 0, 'idempotency_key' => $key];
    }
}
