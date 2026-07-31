<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\LightingCommand;
use App\Models\LightingDevice;
use App\Models\LightingReservation;
use App\Models\LightingScheduleInterval;
use App\Models\LightingSetting;
use App\Models\LightingZone;
use App\Models\User;
use App\Services\LightingCommandDispatcher;
use App\Services\LightingHostValidator;
use App\Services\LightingQuoteService;
use App\Services\LightingReservationService;
use App\Services\LightingSchedulePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LightingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-01 08:00:00');
        $this->seed();
    }

    public function test_device_credentials_are_encrypted_write_only_and_defaults_are_safe(): void
    {
        $settings = LightingSetting::whereNull('sport_code_id')->firstOrFail();
        $device = LightingDevice::where('code', 'LIGHT-DEMO-DRY')->firstOrFail();
        $device->update(['username' => 'lighting-admin', 'password' => 'never-return-this']);
        $raw = DB::table('lighting_devices')->find($device->id);

        $this->assertFalse($settings->automation_enabled);
        $this->assertTrue($settings->dry_run);
        $this->assertTrue($device->fresh()->dry_run);
        $this->assertNotSame('lighting-admin', $raw->username);
        $this->assertNotSame('never-return-this', $raw->password);
        $this->assertTrue($device->fresh()->credentials_configured);
        $this->assertArrayNotHasKey('username', $device->fresh()->toArray());
        $this->assertArrayNotHasKey('password', $device->fresh()->toArray());
        $this->assertStringNotContainsString('never-return-this', json_encode($device->fresh()));
    }

    public function test_host_validator_accepts_only_private_ips_and_local_names(): void
    {
        $validator = app(LightingHostValidator::class);
        $this->assertSame('192.168.10.15', $validator->validate('192.168.10.15'));
        $this->assertSame('court-lights.local', $validator->validate('COURT-LIGHTS.LOCAL'));

        foreach (['https://lights.local', 'lights.local/rpc', 'lights.local?x=1', 'lights.local#relay', 'admin@lights.local', '8.8.8.8', 'example.com'] as $host) {
            try {
                $validator->validate($host);
                $this->fail("Unsafe host was accepted: {$host}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('network_host', $e->errors());
                $this->assertStringNotContainsString($host, $e->getMessage());
            }
        }
    }

    public function test_required_and_optional_quotes_are_server_calculated(): void
    {
        $facility = \App\Models\Facility::where('code', 'TEN-1')->firstOrFail();
        $service = app(LightingQuoteService::class);
        $optional = $service->quote($facility, Carbon::parse('2026-08-03 14:00'), Carbon::parse('2026-08-03 15:00'), 'adult_member', false);
        $selected = $service->quote($facility, Carbon::parse('2026-08-03 14:00'), Carbon::parse('2026-08-03 15:00'), 'adult_member', true);
        $required = $service->quote($facility, Carbon::parse('2026-08-03 18:00'), Carbon::parse('2026-08-03 19:00'), 'adult_member', false);

        $this->assertTrue($optional['available']);
        $this->assertFalse($optional['applies']);
        $this->assertSame(0, $optional['amount']);
        $this->assertTrue($selected['applies']);
        $this->assertSame(5000, $selected['amount']);
        $this->assertTrue($required['required']);
        $this->assertTrue($required['applies']);
        $this->assertSame(8000, $required['amount']);
        $this->assertSame(60, $required['billable_minutes']);
        $this->assertSame('sport_code', $required['financial_owner_type']);
    }

    public function test_reservation_is_idempotent_and_planning_merges_adjacent_bookings(): void
    {
        $zone = LightingZone::where('code', 'TENNIS-LIGHT-A')->firstOrFail();
        $template = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $first = $template->replicate()->fill([
            'booking_reference' => 'BKG-LIGHT-FIRST',
            'confirmation_key' => (string) Str::uuid(),
            'starts_at' => Carbon::parse('2026-08-03 18:00'),
            'ends_at' => Carbon::parse('2026-08-03 19:00'),
        ]);
        $first->save();
        $quote = app(LightingQuoteService::class)->quote($first->facility, $first->starts_at, $first->ends_at, $first->profile, true);
        $one = app(LightingReservationService::class)->reserve($first, $quote);
        $again = app(LightingReservationService::class)->reserve($first, $quote);

        $second = $template->replicate()->fill([
            'booking_reference' => 'BKG-LIGHT-ADJACENT',
            'confirmation_key' => (string) Str::uuid(),
            'starts_at' => Carbon::parse('2026-08-03 19:00'),
            'ends_at' => Carbon::parse('2026-08-03 20:00'),
        ]);
        $second->save();
        $quoteTwo = app(LightingQuoteService::class)->quote($second->facility, $second->starts_at, $second->ends_at, $second->profile, true);
        app(LightingReservationService::class)->reserve($second, $quoteTwo);
        app(LightingSchedulePlanner::class)->planChannel($zone->lighting_device_channel_id);
        app(LightingSchedulePlanner::class)->planChannel($zone->lighting_device_channel_id);

        $intervals = LightingScheduleInterval::where('status', 'planned')
            ->where('lighting_device_channel_id', $zone->lighting_device_channel_id)
            ->whereHas('reservations', fn ($query) => $query->whereKey($one->id))
            ->get();
        $this->assertSame($one->id, $again->id);
        $this->assertSame(2, LightingReservation::whereIn('booking_id', [$first->id, $second->id])->count());
        $this->assertCount(1, $intervals);
        $this->assertSame(64, strlen($intervals->first()->planning_key));
        $this->assertSame(2, $intervals->first()->reservations()->count());
        $this->assertSame(2, $intervals->first()->commands()->count());

        app(LightingReservationService::class)->cancel($first);
        $this->assertSame('cancelled', $one->fresh()->status);
        $this->assertSame('scheduled', $second->lightingReservations()->first()->fresh()->status);
        $this->assertSame(1, LightingScheduleInterval::where('status', 'planned')->whereHas('reservations', fn ($query) => $query->where('booking_id', $second->id))->count());
    }

    public function test_dry_run_dispatch_is_idempotent_and_stale_or_disabled_commands_are_blocked(): void
    {
        LightingSetting::whereNull('sport_code_id')->update(['automation_enabled' => true, 'dry_run' => true]);
        $reservation = LightingReservation::firstOrFail();
        $reservation->update(['status' => 'scheduled']);
        app(LightingSchedulePlanner::class)->planChannel($reservation->zone->lighting_device_channel_id);
        $command = LightingCommand::where('command_type', 'switch_on')->firstOrFail();
        $command->update(['due_at' => now()]);

        $first = app(LightingCommandDispatcher::class)->dispatch();
        $second = app(LightingCommandDispatcher::class)->dispatch();
        $this->assertSame(1, $first['succeeded']);
        $this->assertSame('succeeded', $command->fresh()->status);
        $this->assertSame('dry_run_on', $command->fresh()->provider_result_code);
        $this->assertSame(0, $second['examined']);

        $interval = $command->interval;
        $stale = LightingCommand::create([
            'command_uuid' => (string) Str::uuid(),
            'lighting_schedule_interval_id' => $interval->id,
            'lighting_device_channel_id' => $command->lighting_device_channel_id,
            'command_type' => 'switch_on',
            'due_at' => now(),
            'status' => 'pending',
            'idempotency_key' => 'test-stale-command',
        ]);
        $interval->update(['ends_at' => now()->subMinute()]);
        $result = app(LightingCommandDispatcher::class)->dispatch();
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('cancelled', $stale->fresh()->status);
        $this->assertSame('command_obsolete', $stale->fresh()->safe_error_code);
    }

    public function test_seed_is_rerunnable_without_duplicate_lighting_identities(): void
    {
        $before = [
            LightingDevice::count(),
            DB::table('lighting_device_channels')->count(),
            LightingZone::count(),
            DB::table('facility_lighting_zone')->count(),
            DB::table('lighting_tariffs')->count(),
            DB::table('lighting_requirement_policies')->count(),
        ];
        $this->seed();
        $after = [
            LightingDevice::count(),
            DB::table('lighting_device_channels')->count(),
            LightingZone::count(),
            DB::table('facility_lighting_zone')->count(),
            DB::table('lighting_tariffs')->count(),
            DB::table('lighting_requirement_policies')->count(),
        ];

        $this->assertSame($before, $after);
        $this->assertSame(0, LightingDevice::where('dry_run', false)->count());
        $this->assertSame(0, LightingDevice::whereNotNull('network_host')->count());
        $this->assertSame(0, LightingDevice::whereNotNull('password')->count());
    }

    public function test_lighting_dashboard_is_authorized_and_never_serializes_credentials(): void
    {
        User::factory()->create([
            'role' => 'hsc_super_admin',
            'active' => true,
            'api_token_hash' => hash('sha256', 'lighting-admin-token'),
        ]);
        $device = LightingDevice::where('code', 'LIGHT-DEMO-DRY')->firstOrFail();
        $device->update(['username' => 'dashboard-secret-user', 'password' => 'dashboard-secret-password']);

        $response = $this->withToken('lighting-admin-token')->getJson('/api/lighting')->assertOk();
        $response->assertJsonPath('devices.0.credentials_configured', true);
        $body = $response->getContent();
        $this->assertStringNotContainsString('dashboard-secret-user', $body);
        $this->assertStringNotContainsString('dashboard-secret-password', $body);

        $member = User::factory()->create([
            'role' => 'member',
            'active' => true,
            'api_token_hash' => hash('sha256', 'lighting-member-token'),
        ]);
        $this->withToken('lighting-member-token')->getJson('/api/lighting')->assertForbidden();
        $this->assertTrue($member->active);
    }

    public function test_live_device_configuration_requires_exact_confirmation_and_safe_host(): void
    {
        User::factory()->create([
            'role' => 'hsc_super_admin',
            'active' => true,
            'api_token_hash' => hash('sha256', 'lighting-live-admin'),
        ]);
        $payload = [
            'name' => 'Safety gate device',
            'code' => 'LIGHT-SAFETY-GATE',
            'provider' => 'shelly',
            'enabled' => false,
            'dry_run' => false,
            'network_host' => null,
        ];

        $this->withToken('lighting-live-admin')->postJson('/api/lighting/devices', $payload)
            ->assertUnprocessable()
            ->assertJsonMissing(['network_host', 'password']);
        $this->withToken('lighting-live-admin')->postJson('/api/lighting/devices', [
            ...$payload,
            'network_host' => 'lights.local/rpc',
            'live_confirmation' => 'ENABLE LIVE LIGHTING',
        ])->assertUnprocessable();
        $created = $this->withToken('lighting-live-admin')->postJson('/api/lighting/devices', [
            ...$payload,
            'live_confirmation' => 'ENABLE LIVE LIGHTING',
        ])->assertCreated()->assertJsonMissing(['username', 'password'])->json();

        $this->assertFalse($created['dry_run']);
        $this->withToken('lighting-live-admin')->putJson("/api/lighting/devices/{$created['id']}", [
            ...$payload,
            'dry_run' => true,
        ])->assertOk()->assertJsonPath('dry_run', true);
    }
}
