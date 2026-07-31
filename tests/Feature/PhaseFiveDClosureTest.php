<?php

namespace Tests\Feature;

use App\Models\AccessCredential;
use App\Models\AccessEmergencyAction;
use App\Models\AttendanceCorrection;
use App\Models\Booking;
use App\Models\BookingCheckInToken;
use App\Models\FacilityCheckInToken;
use App\Models\User;
use App\Services\BookingAccessGrantService;
use App\Services\BookingCheckInPolicyResolver;
use App\Services\CheckInOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseFiveDClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-01 10:00:00');
        $this->seed();
    }

    public function test_facility_qr_is_opaque_rotatable_scoped_and_replay_safe(): void
    {
        $booking = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $booking->update(['starts_at' => now()->addMinutes(5), 'ends_at' => now()->addMinutes(65)]);
        $booking->attendance()->delete();
        $admin = User::factory()->create(['role' => 'hsc_admin', 'active' => true]);
        $member = User::factory()->create(['member_id' => $booking->member_id, 'active' => true, 'api_token_hash' => hash('sha256', 'facility-owner')]);
        $first = app(CheckInOperationsService::class)->rotateFacilityToken($admin, $booking->facility);
        $this->assertSame(64, strlen($first['token']));
        $this->assertStringNotContainsString($booking->facility->name, $first['token']);
        $this->withToken('facility-owner')->getJson('/api/facility-check-in/'.$first['token'])->assertOk()->assertJsonCount(1, 'candidates');
        $this->withToken('facility-owner')->postJson('/api/facility-check-in/'.$first['token'], ['booking_id' => $booking->id])->assertOk()->assertJsonPath('status', 'checked_in');
        $this->withToken('facility-owner')->postJson('/api/facility-check-in/'.$first['token'], ['booking_id' => $booking->id])->assertOk()->assertJsonPath('status', 'checked_in');
        $second = app(CheckInOperationsService::class)->rotateFacilityToken($admin, $booking->facility);
        $this->withToken('facility-owner')->getJson('/api/facility-check-in/'.$first['token'])->assertNotFound()->assertJsonMissing(['facility_id', 'booking_reference']);
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertSame(hash('sha256', $second['token']), FacilityCheckInToken::first()->token_hash);
    }

    public function test_attendance_correction_preserves_history_requires_reason_and_is_idempotent(): void
    {
        $booking = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $admin = User::factory()->create(['role' => 'hsc_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'correction-admin')]);
        $attendance = $booking->attendance;
        $key = (string) Str::uuid();
        $this->withToken('correction-admin')->postJson("/api/attendance/{$attendance->id}/corrections", ['action' => 'mark_late', 'reason' => ''])->assertUnprocessable();
        $payload = ['action' => 'mark_late', 'reason' => 'Verified late arrival at reception', 'idempotency_key' => $key];
        $this->withToken('correction-admin')->postJson("/api/attendance/{$attendance->id}/corrections", $payload)->assertOk()->assertJsonPath('status', 'late_checked_in');
        $this->withToken('correction-admin')->postJson("/api/attendance/{$attendance->id}/corrections", $payload)->assertOk();
        $this->assertSame(1, AttendanceCorrection::where('idempotency_key', $key)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'booking.attendance_corrected']);
    }

    public function test_emergency_suspension_and_restore_preserve_permanent_access_and_finance(): void
    {
        $booking = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $booking->update(['starts_at' => now()->addMinutes(5), 'ends_at' => now()->addMinutes(65)]);
        $grant = app(BookingAccessGrantService::class)->ensure($booking, app(BookingCheckInPolicyResolver::class)->resolve($booking))[0];
        $admin = User::factory()->create(['role' => 'hsc_admin', 'active' => true]);
        $permanent = AccessCredential::count();
        $amount = $booking->total_amount;
        $key = (string) Str::uuid();
        $action = app(CheckInOperationsService::class)->suspend($admin, ['scope_type' => 'zone', 'scope_id' => $grant->access_zone_id, 'reason' => 'Emergency gate maintenance isolation', 'idempotency_key' => $key]);
        $this->assertSame('suspended', $grant->fresh()->status);
        $this->assertSame(1, AccessEmergencyAction::where('idempotency_key', $key)->count());
        $this->assertSame($permanent, AccessCredential::count());
        $this->assertSame($amount, $booking->fresh()->total_amount);
        app(CheckInOperationsService::class)->restore($admin, $action, 'Maintenance completed and verified');
        $this->assertSame('restored', $action->fresh()->status);
        $this->assertContains($grant->fresh()->status, ['eligible', 'provisioned']);
        $this->assertSame($permanent, AccessCredential::count());
    }

    public function test_dashboard_and_serialization_hide_all_tokens_credentials_and_hosts(): void
    {
        $admin = User::factory()->create(['role' => 'hsc_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'closure-admin')]);
        $booking = Booking::firstOrFail();
        BookingCheckInToken::create(['booking_id' => $booking->id, 'mode' => 'booking_qr', 'token_hash' => hash('sha256', 'raw-secret'), 'token_prefix' => 'raw-se', 'issued_at' => now(), 'expires_at' => now()->addHour(), 'maximum_uses' => 1]);
        $endpoint = \App\Models\AccessEndpoint::firstOrFail();
        $endpoint->update(['endpoint_host' => '10.1.2.3', 'username' => 'private-user', 'password' => 'private-pass']);
        $body = $this->withToken('closure-admin')->getJson('/api/check-in-operations')->assertOk()->getContent();
        foreach (['raw-secret', hash('sha256', 'raw-secret'), '10.1.2.3', 'private-user', 'private-pass'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertArrayNotHasKey('token_hash', BookingCheckInToken::first()->toArray());
        $this->assertArrayNotHasKey('endpoint_host', $endpoint->fresh()->toArray());
    }
}
