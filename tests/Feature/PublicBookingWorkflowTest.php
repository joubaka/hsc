<?php

namespace Tests\Feature;

use App\Models\AccessCommand;
use App\Models\AccessEndpoint;
use App\Models\AccessZone;
use App\Models\Booking;
use App\Models\BookingAccessGrant;
use App\Models\BookingHold;
use App\Models\BookingPricingRule;
use App\Models\BookingSetting;
use App\Models\Facility;
use App\Models\PublicBookingManagementToken;
use App\Models\PublicBookingSetting;
use App\Models\PublicVisitor;
use App\Models\SportCode;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicBookingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['role' => 'hsc_super_admin', 'active' => true, 'protected_account' => true]);
        $sport = SportCode::create(['name' => 'Tennis', 'slug' => 'tennis', 'colour' => '#2E7D32']);
        $venue = Venue::create(['name' => 'HSC', 'code' => 'HSC', 'timezone' => 'Africa/Johannesburg', 'active' => true]);
        $this->facility = Facility::create(['venue_id' => $venue->id, 'sport_code_id' => $sport->id, 'name' => 'Visitor Court', 'code' => 'VIS-1', 'facility_type' => 'tennis_court', 'capacity' => 4, 'publicly_visible' => true, 'bookable' => true, 'active' => true, 'start_interval_minutes' => 30]);
        foreach (range(0, 6) as $day) {
            $this->facility->openingHours()->create(['day_of_week' => $day, 'opens_at' => '07:00:00', 'closes_at' => '21:00:00', 'active' => true]);
        }
        BookingSetting::create(['timezone' => 'Africa/Johannesburg', 'currency' => 'ZAR', 'hold_minutes' => 10, 'default_hourly_rate_cents' => 12000, 'default_advance_days' => 14, 'default_minimum_notice_minutes' => 30, 'default_min_duration_minutes' => 30, 'default_max_duration_minutes' => 120, 'default_start_interval_minutes' => 30, 'default_max_future_bookings' => 4]);
        BookingPricingRule::create(['name' => 'Public visitor rate', 'scope_type' => 'central', 'profile' => 'public_visitor', 'pricing_method' => 'hourly', 'amount_cents' => 15000, 'guest_fee_cents' => 2500, 'priority' => 50, 'active' => true]);
        PublicBookingSetting::create(['enabled' => true, 'dry_run_checkout_enabled' => true, 'email_verification_required' => true, 'mobile_verification_required' => false, 'payment_required' => true, 'hold_minutes' => 10, 'maximum_active_holds' => 2, 'maximum_future_bookings' => 3, 'maximum_participants' => 4]);
    }

    public function test_verified_visitor_checkout_is_paid_confirmed_private_and_idempotent(): void
    {
        [$token, $code] = $this->verifiedVisitor();
        $start = now()->addDays(2)->setTimezone('Africa/Johannesburg')->setTime(10, 0);
        $end = $start->copy()->addHour();
        $holdKey = (string) Str::uuid();
        // Match the browser contract: a local start with an explicit offset and a
        // JavaScript-generated UTC end. Persistence must normalize both consistently.
        $body = ['starts_at' => $start->toIso8601String(), 'ends_at' => $end->utc()->toIso8601String(), 'participants' => [['name' => 'Guest One', 'age_group' => 'adult']], 'lighting_requested' => false, 'idempotency_key' => $holdKey, 'accept_terms' => true, 'accept_privacy' => true, 'accept_facility_rules' => true];
        $hold = $this->withHeader('X-Visitor-Token', $token)->postJson("/api/public/booking/facilities/{$this->facility->id}/holds", $body)->assertCreated()->json();
        $this->assertSame(17500, $hold['price']['total_amount']);
        $persistedHold = BookingHold::where('hold_reference', $hold['hold_reference'])->firstOrFail();
        $this->assertSame(60.0, $persistedHold->starts_at->diffInMinutes($persistedHold->ends_at));
        $this->withHeader('X-Visitor-Token', $token)->postJson("/api/public/booking/holds/{$hold['hold_reference']}/checkout", ['outcome' => 'success'])->assertOk()->assertJson(['status' => 'paid']);
        $confirmationKey = (string) Str::uuid();
        $confirmed = $this->withHeader('X-Visitor-Token', $token)->postJson("/api/public/booking/holds/{$hold['hold_reference']}/confirm", ['idempotency_key' => $confirmationKey, 'participants' => $body['participants']])->assertCreated()->json();
        $this->assertSame('confirmed', $confirmed['booking']['status']);
        $this->assertStringStartsWith('/manage-booking/', $confirmed['management_path']);
        $this->withHeader('X-Visitor-Token', $token)->postJson("/api/public/booking/holds/{$hold['hold_reference']}/confirm", ['idempotency_key' => $confirmationKey, 'participants' => $body['participants']])->assertCreated()->assertJson(['outcome' => 'already_confirmed']);
        $this->assertDatabaseCount('public_visitors', 1);
        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('public_booking_management_tokens', 1);
        $this->assertDatabaseMissing('public_booking_management_tokens', ['token_hash' => basename($confirmed['management_path'])]);
        $this->assertDatabaseMissing('visitor_verification_challenges', ['token_hash' => $code]);
    }

    public function test_unverified_blocked_and_frontend_price_inputs_cannot_confirm(): void
    {
        $identity = $this->postJson('/api/public/booking/identity', ['first_name' => 'No', 'last_name' => 'Verify', 'email' => 'no@example.test', 'mobile' => '+27 82 555 1010'])->assertOk()->json();
        $start = now()->addDays(2)->setTime(11, 0);
        $this->withHeader('X-Visitor-Token', $identity['session_token'])->postJson("/api/public/booking/facilities/{$this->facility->id}/holds", ['starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'participants' => [], 'idempotency_key' => (string) Str::uuid(), 'accept_terms' => true, 'accept_privacy' => true, 'accept_facility_rules' => true, 'total_amount' => 1])->assertUnprocessable();
        PublicVisitor::where('email', 'no@example.test')->update(['status' => 'blocked']);
        $this->withHeader('X-Visitor-Token', $identity['session_token'])->postJson('/api/public/booking/verification/request', ['channel' => 'email', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_verification_regeneration_revokes_old_code_and_limits_bad_attempts(): void
    {
        $identity = $this->postJson('/api/public/booking/identity', ['first_name' => 'Code', 'last_name' => 'Test', 'email' => 'codes@example.test', 'mobile' => '0825552020'])->json();
        $token = $identity['session_token'];
        $old = $this->withHeader('X-Visitor-Token', $token)->postJson('/api/public/booking/verification/request', ['channel' => 'email', 'idempotency_key' => (string) Str::uuid()])->assertAccepted()->json('development_code');
        $new = $this->withHeader('X-Visitor-Token', $token)->postJson('/api/public/booking/verification/request', ['channel' => 'email', 'idempotency_key' => (string) Str::uuid()])->assertAccepted()->json('development_code');
        $this->withHeader('X-Visitor-Token', $token)->postJson('/api/public/booking/verification/verify', ['channel' => 'email', 'code' => $old])->assertUnprocessable();
        $this->withHeader('X-Visitor-Token', $token)->postJson('/api/public/booking/verification/verify', ['channel' => 'email', 'code' => $new])->assertOk()->assertJson(['verified' => true]);
        $this->assertDatabaseCount('public_visitors', 1);
    }

    public function test_management_rotation_and_cancellation_are_safe_and_idempotent(): void
    {
        [$token] = $this->verifiedVisitor('manage@example.test', '0825553030');
        $start = now()->addDays(3)->setTime(12, 0);
        $hold = $this->withHeader('X-Visitor-Token', $token)->postJson("/api/public/booking/facilities/{$this->facility->id}/holds", ['starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'participants' => [], 'idempotency_key' => (string) Str::uuid(), 'accept_terms' => true, 'accept_privacy' => true, 'accept_facility_rules' => true])->json();
        $this->withHeader('X-Visitor-Token', $token)->postJson("/api/public/booking/holds/{$hold['hold_reference']}/checkout", ['outcome' => 'success'])->assertOk();
        $confirmed = $this->withHeader('X-Visitor-Token', $token)->postJson("/api/public/booking/holds/{$hold['hold_reference']}/confirm", ['idempotency_key' => (string) Str::uuid(), 'participants' => []])->json();
        $raw = basename($confirmed['management_path']);
        $this->putJson('/api/public/booking/manage/'.$raw.'/participants', ['participants' => [['name' => 'Updated Guest', 'age_group' => 'adult']]])->assertOk()->assertJsonPath('participants.0.display_name', 'Updated Guest');
        $this->putJson('/api/public/booking/manage/'.$raw.'/participants', ['participants' => [['name' => 'Junior Guest', 'age_group' => 'junior', 'guardian_consent' => false]]])->assertUnprocessable();
        $this->getJson('/api/public/booking/manage/'.$raw)->assertOk()->assertJsonMissingPath('token_hash')->assertJsonMissingPath('member');
        $rotated = $this->postJson('/api/public/booking/manage/'.$raw.'/rotate')->assertOk()->json();
        $this->getJson('/api/public/booking/manage/'.$raw)->assertNotFound();
        $fresh = basename($rotated['management_path']);
        $this->postJson('/api/public/booking/manage/'.$fresh.'/cancel', ['reason' => 'Plans have changed'])->assertOk()->assertJson(['status' => 'cancelled']);
        $this->postJson('/api/public/booking/manage/'.$fresh.'/cancel', ['reason' => 'Plans have changed'])->assertNotFound();
        $this->assertDatabaseCount('booking_cancellation_compensations', 1);
        $this->assertDatabaseCount('booking_refund_requests', 1);
        $this->assertSame('cancelled', Booking::first()->status);
    }

    public function test_public_management_check_in_uses_canonical_temporary_access_and_is_idempotent(): void
    {
        [$token] = $this->verifiedVisitor('checkin@example.test', '0825554040');
        $start = now()->addDays(2)->setTime(13, 0);
        $hold = $this->withHeader('X-Visitor-Token', $token)->postJson("/api/public/booking/facilities/{$this->facility->id}/holds", ['starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addHour()->toIso8601String(), 'participants' => [], 'idempotency_key' => (string) Str::uuid(), 'accept_terms' => true, 'accept_privacy' => true, 'accept_facility_rules' => true])->assertCreated()->json();
        $this->withHeader('X-Visitor-Token', $token)->postJson("/api/public/booking/holds/{$hold['hold_reference']}/checkout", ['outcome' => 'success'])->assertOk();
        $confirmed = $this->withHeader('X-Visitor-Token', $token)->postJson("/api/public/booking/holds/{$hold['hold_reference']}/confirm", ['idempotency_key' => (string) Str::uuid(), 'participants' => []])->assertCreated()->json();
        $raw = basename($confirmed['management_path']);
        $booking = Booking::firstOrFail();
        $booking->update(['starts_at' => now()->addMinutes(5), 'ends_at' => now()->addMinutes(65)]);
        $booking->attendance()->delete();

        $zone = AccessZone::create(['venue_id' => $this->facility->venue_id, 'sport_code_id' => $this->facility->sport_code_id, 'code' => 'PUBLIC-TEST-ENTRY', 'name' => 'Public test entrance', 'active' => true, 'provider_mode' => 'dry_run', 'requires_booking' => true, 'allows_permanent_member_access' => false, 'allows_temporary_booking_access' => true]);
        $zone->facilities()->attach($this->facility->id);
        AccessEndpoint::create(['access_zone_id' => $zone->id, 'code' => 'PUBLIC-TEST-ENDPOINT', 'name' => 'Public dry-run endpoint', 'provider' => 'dry_run', 'enabled' => true, 'dry_run' => true]);

        $first = $this->postJson('/api/public/booking/manage/'.$raw.'/check-in')->assertOk();
        $second = $this->postJson('/api/public/booking/manage/'.$raw.'/check-in')->assertOk();
        $first->assertJsonPath('check_in.status', 'checked_in');
        $second->assertJsonPath('check_in.status', 'checked_in');
        $this->assertSame(1, BookingAccessGrant::where('booking_id', $booking->id)->count());
        $this->assertSame(1, AccessCommand::whereHas('grant', fn ($query) => $query->where('booking_id', $booking->id))->count());
        $this->assertDatabaseMissing('booking_access_grants', ['member_id' => 1]);

        PublicBookingSetting::whereNull('sport_code_id')->update(['temporary_access_eligible' => false]);
        $withoutAccess = $booking->replicate();
        $withoutAccess->booking_reference = 'BKG-PUBLIC-NO-ACCESS';
        $withoutAccess->booking_hold_id = null;
        $withoutAccess->confirmation_key = (string) Str::uuid();
        $withoutAccess->starts_at = now()->addMinutes(10);
        $withoutAccess->ends_at = now()->addMinutes(70);
        $withoutAccess->save();
        app(\App\Services\BookingCheckInService::class)->ensure($withoutAccess);
        $rawWithoutAccess = Str::random(64);
        PublicBookingManagementToken::create(['booking_id' => $withoutAccess->id, 'public_visitor_id' => $withoutAccess->public_visitor_id, 'token_hash' => hash('sha256', $rawWithoutAccess), 'token_prefix' => substr($rawWithoutAccess, 0, 8), 'purpose' => 'manage', 'issued_at' => now(), 'expires_at' => now()->addDay(), 'idempotency_key' => (string) Str::uuid()]);
        $this->postJson('/api/public/booking/manage/'.$rawWithoutAccess.'/check-in')->assertOk()->assertJsonPath('check_in.status', 'checked_in');
        $this->assertSame(0, BookingAccessGrant::where('booking_id', $withoutAccess->id)->count());
        $this->assertSame(0, AccessCommand::whereHas('grant', fn ($query) => $query->where('booking_id', $withoutAccess->id))->count());
    }

    public function test_public_availability_exposes_no_booking_or_member_identity(): void
    {
        $from = now()->addDay()->startOfDay();
        $to = $from->copy()->addDays(7);
        $payload = $this->getJson('/api/public/availability?from='.urlencode($from->toIso8601String()).'&to='.urlencode($to->toIso8601String()))->assertOk()->json();
        $json = json_encode($payload);
        $this->assertStringNotContainsString('member_id', $json);
        $this->assertStringNotContainsString('email', $json);
        $this->assertStringNotContainsString('booking_reference', $json);
    }

    public function test_public_booking_administration_is_scoped_masked_and_write_authorized(): void
    {
        $identity = $this->postJson('/api/public/booking/identity', ['first_name' => 'Admin', 'last_name' => 'Visitor', 'email' => 'admin-visitor@example.test', 'mobile' => '0825555050'])->assertOk()->json();
        $visitor = PublicVisitor::where('public_reference', $identity['visitor_reference'])->firstOrFail();
        $central = User::factory()->create(['role' => 'hsc_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'public-central')]);
        $sportAdmin = User::factory()->create(['role' => 'sport_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'public-sport')]);
        $viewer = User::factory()->create(['role' => 'sport_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'public-viewer')]);
        $disabled = User::factory()->create(['role' => 'hsc_admin', 'active' => false, 'api_token_hash' => hash('sha256', 'public-disabled')]);
        $sportAdmin->sportCodes()->attach($this->facility->sport_code_id, ['role' => 'administrator']);
        $viewer->sportCodes()->attach($this->facility->sport_code_id, ['role' => 'viewer']);
        $codeSetting = PublicBookingSetting::create(['sport_code_id' => $this->facility->sport_code_id, 'enabled' => true, 'dry_run_checkout_enabled' => true]);
        $globalSetting = PublicBookingSetting::whereNull('sport_code_id')->firstOrFail();

        $this->withToken('public-central')->patchJson("/api/public-bookings/visitors/{$visitor->public_reference}/status", ['status' => 'restricted', 'reason' => 'Repeated policy breach'])->assertOk()->assertJson(['status' => 'restricted']);
        $this->withToken('public-sport')->patchJson("/api/public-bookings/visitors/{$visitor->public_reference}/status", ['status' => 'verified', 'reason' => 'Not globally authorized'])->assertForbidden();
        $this->withToken('public-sport')->patchJson("/api/public-bookings/settings/{$codeSetting->id}", ['maximum_active_holds' => 3])->assertOk()->assertJson(['maximum_active_holds' => 3]);
        $this->withToken('public-sport')->patchJson("/api/public-bookings/settings/{$globalSetting->id}", ['enabled' => false])->assertForbidden();
        $this->withToken('public-viewer')->patchJson("/api/public-bookings/settings/{$codeSetting->id}", ['enabled' => false])->assertForbidden();
        $dashboard = $this->withToken('public-central')->getJson('/api/public-bookings')->assertOk()->json();
        $this->assertStringNotContainsString('admin-visitor@example.test', json_encode($dashboard));
        $this->assertStringNotContainsString('0825555050', json_encode($dashboard));
        $this->withToken('public-disabled')->getJson('/api/public-bookings')->assertUnauthorized();
    }

    private function verifiedVisitor(string $email = 'visitor@example.test', string $mobile = '0825550000'): array
    {
        $identity = $this->postJson('/api/public/booking/identity', ['first_name' => 'Public', 'last_name' => 'Visitor', 'email' => $email, 'mobile' => $mobile, 'marketing_consent' => false])->assertOk()->json();
        $token = $identity['session_token'];
        $code = $this->withHeader('X-Visitor-Token', $token)->postJson('/api/public/booking/verification/request', ['channel' => 'email', 'idempotency_key' => (string) Str::uuid()])->assertAccepted()->json('development_code');
        $this->withHeader('X-Visitor-Token', $token)->postJson('/api/public/booking/verification/verify', ['channel' => 'email', 'code' => $code])->assertOk();

        return [$token, $code];
    }
}
