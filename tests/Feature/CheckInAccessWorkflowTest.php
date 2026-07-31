<?php

namespace Tests\Feature;

use App\Models\AccessCommand;
use App\Models\AccessEndpoint;
use App\Models\Booking;
use App\Models\BookingAccessGrant;
use App\Models\BookingAttendance;
use App\Models\BookingCheckInSetting;
use App\Models\BookingCheckInToken;
use App\Models\User;
use App\Services\AccessCommandDispatcher;
use App\Services\BookingAccessGrantService;
use App\Services\BookingCheckInService;
use App\Services\NoShowProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CheckInAccessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-01 10:00:00');
        $this->seed();
    }

    public function test_check_in_settings_are_safe_and_early_member_check_in_is_rejected(): void
    {
        $setting = BookingCheckInSetting::whereNull('sport_code_id')->firstOrFail();
        $booking = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $user = User::factory()->create(['member_id' => $booking->member_id, 'active' => true, 'api_token_hash' => hash('sha256', 'checkin-member')]);

        $this->assertFalse($setting->automatic_release_enabled);
        $this->withToken('checkin-member')->postJson("/api/bookings/{$booking->id}/check-in", ['method' => 'member'])
            ->assertUnprocessable()->assertJsonMissing(['member_id', 'booking_reference']);
        $this->assertNull($booking->attendance->fresh()->checked_in_at);
        $this->assertTrue($user->active);
    }

    public function test_opaque_token_self_check_in_is_idempotent_and_creates_scoped_dry_run_access(): void
    {
        $booking = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $booking->update(['starts_at' => now()->addMinutes(5), 'ends_at' => now()->addMinutes(65)]);
        $booking->attendance()->delete();
        $user = User::factory()->create(['member_id' => $booking->member_id, 'active' => true, 'api_token_hash' => hash('sha256', 'checkin-owner')]);
        $permanentAccessBefore = \App\Models\AccessCredential::count();

        $issued = $this->withToken('checkin-owner')->postJson("/api/bookings/{$booking->id}/check-in-token", ['mode' => 'booking_qr'])->assertCreated()->json();
        $this->assertArrayHasKey('token', $issued);
        $stored = BookingCheckInToken::where('booking_id', $booking->id)->firstOrFail();
        $this->assertNotSame($issued['token'], $stored->token_hash);
        $this->assertSame(hash('sha256', $issued['token']), $stored->token_hash);
        $this->assertStringNotContainsString($booking->booking_reference, $issued['token']);

        $first = $this->withToken('checkin-owner')->postJson("/api/bookings/{$booking->id}/check-in", ['method' => 'booking_qr', 'token' => $issued['token']])->assertOk();
        $second = $this->withToken('checkin-owner')->postJson("/api/bookings/{$booking->id}/check-in", ['method' => 'member'])->assertOk();
        $first->assertJsonPath('status', 'checked_in');
        $second->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, BookingAttendance::where('booking_id', $booking->id)->count());
        $this->assertSame(1, BookingAccessGrant::where('booking_id', $booking->id)->count());
        $this->assertSame(1, AccessCommand::whereHas('grant', fn ($query) => $query->where('booking_id', $booking->id))->count());
        $this->assertSame($permanentAccessBefore, \App\Models\AccessCredential::count());
    }

    public function test_member_cannot_check_in_another_booking_and_short_code_replay_is_blocked(): void
    {
        $booking = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $booking->update(['starts_at' => now()->addMinutes(5), 'ends_at' => now()->addMinutes(65)]);
        $booking->attendance()->delete();
        $other = User::factory()->create(['active' => true, 'api_token_hash' => hash('sha256', 'other-member')]);
        $this->withToken('other-member')->postJson("/api/bookings/{$booking->id}/check-in", ['method' => 'member'])->assertForbidden();

        $owner = User::factory()->create(['member_id' => $booking->member_id, 'active' => true, 'api_token_hash' => hash('sha256', 'short-owner')]);
        $code = app(BookingCheckInService::class)->issueToken($booking, 'short_code')['token'];
        app(BookingCheckInService::class)->checkIn($owner, $booking, 'short_code', $code);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(BookingCheckInService::class)->checkIn($owner, $booking, 'short_code', $code);
    }

    public function test_no_show_is_idempotent_preserves_finance_and_release_is_disabled_by_default(): void
    {
        $booking = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $booking->update(['starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(5)]);
        $booking->attendance()->delete();
        $attendance = app(BookingCheckInService::class)->ensure($booking);
        $amount = $booking->total_amount;
        $first = app(NoShowProcessor::class)->process();
        $second = app(NoShowProcessor::class)->process();

        $this->assertSame(1, $first['marked']);
        $this->assertSame(0, $first['released']);
        $this->assertSame(0, $second['marked']);
        $this->assertSame('no_show', $attendance->fresh()->status);
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame($amount, $booking->fresh()->total_amount);
        $this->assertDatabaseCount('credit_notes', 0);
    }

    public function test_explicit_release_revokes_access_without_financial_mutation(): void
    {
        $booking = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $booking->update(['starts_at' => now()->subHour(), 'ends_at' => now()->subMinutes(5)]);
        $booking->attendance()->delete();
        $attendance = app(BookingCheckInService::class)->ensure($booking);
        $snapshot = $attendance->policy_snapshot;
        $snapshot['automatic_release_eligible'] = true;
        $attendance->update(['policy_snapshot' => $snapshot]);
        app(BookingAccessGrantService::class)->ensure($booking, $snapshot);
        $amount = $booking->total_amount;

        app(NoShowProcessor::class)->process();
        $this->assertSame('released', $attendance->fresh()->status);
        $this->assertSame('released_no_show', $booking->fresh()->status);
        $this->assertSame('revoked', $booking->accessGrants()->first()->status);
        $this->assertSame($amount, $booking->fresh()->total_amount);
        $this->assertDatabaseCount('credit_notes', 0);
    }

    public function test_dry_run_provision_and_revocation_dispatch_once_without_credentials(): void
    {
        $booking = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $booking->update(['starts_at' => now()->addMinutes(5), 'ends_at' => now()->addMinutes(65)]);
        $policy = app(\App\Services\BookingCheckInPolicyResolver::class)->resolve($booking);
        $grant = app(BookingAccessGrantService::class)->ensure($booking, $policy)[0];
        $grant->commands()->update(['due_at' => now()]);
        $first = app(AccessCommandDispatcher::class)->dispatch();
        $second = app(AccessCommandDispatcher::class)->dispatch();
        $this->assertSame(1, $first['succeeded']);
        $this->assertSame(0, $second['examined']);
        $this->assertSame('provisioned', $grant->fresh()->status);

        app(BookingAccessGrantService::class)->revoke($booking, 'acceptance');
        $revoked = app(AccessCommandDispatcher::class)->dispatch();
        $this->assertSame(1, $revoked['succeeded']);
        $this->assertSame('revoked', $grant->fresh()->status);
        $endpoint = AccessEndpoint::firstOrFail();
        $this->assertTrue($endpoint->dry_run);
        $this->assertFalse($endpoint->credentials_configured);
        $this->assertArrayNotHasKey('endpoint_host', $endpoint->toArray());
        $this->assertArrayNotHasKey('password', $endpoint->toArray());
    }

    public function test_access_dashboard_is_authorized_and_credential_free(): void
    {
        $admin = User::factory()->create(['role' => 'hsc_super_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'access-admin')]);
        $endpoint = AccessEndpoint::firstOrFail();
        $endpoint->update(['username' => 'hidden-user', 'password' => 'hidden-password']);
        $body = $this->withToken('access-admin')->getJson('/api/access')->assertOk()->getContent();
        $this->assertStringNotContainsString('hidden-user', $body);
        $this->assertStringNotContainsString('hidden-password', $body);
        $member = User::factory()->create(['role' => 'member', 'active' => true, 'api_token_hash' => hash('sha256', 'access-member')]);
        $this->withToken('access-member')->getJson('/api/access')->assertForbidden();
        $this->assertTrue($admin->active && $member->active);
    }
}
