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
use App\Services\BookingBillingService;
use App\Services\LightingCommandDispatcher;
use App\Services\LightingHostValidator;
use App\Services\LightingQuoteService;
use App\Services\LightingReservationService;
use App\Services\LightingSchedulePlanner;
use App\Services\PaymentService;
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

    public function test_payment_allocation_schedules_lighting_once_and_reversal_supersedes_activation(): void
    {
        $admin = User::where('role', 'hsc_super_admin')->firstOrFail();
        $template = Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->firstOrFail();
        $booking = $template->replicate()->fill([
            'booking_reference' => 'BKG-LIGHT-PAYMENT',
            'confirmation_key' => (string) Str::uuid(),
            'invoice_id' => null,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'starts_at' => Carbon::parse('2026-08-04 18:00'),
            'ends_at' => Carbon::parse('2026-08-04 19:00'),
        ]);
        $quote = app(LightingQuoteService::class)->quote($template->facility, $booking->starts_at, $booking->ends_at, $booking->profile, true);
        $booking->fill([
            'gross_amount' => 12000 + $quote['amount'],
            'total_amount' => 12000 + $quote['amount'],
            'lighting_snapshot' => $quote,
        ])->save();
        $invoice = app(BookingBillingService::class)->createInvoice($admin, $booking);
        $this->assertSame(Booking::class, $invoice->source_type);
        $reservation = app(LightingReservationService::class)->reserve($booking, $quote);
        $payment = app(PaymentService::class)->record($admin, [
            'payment_reference' => 'PAY-LIGHTING-TEST',
            'member_id' => $booking->member_id,
            'payer_name' => 'Lighting test member',
            'currency' => 'ZAR',
            'amount' => $invoice->total_amount,
            'received_on' => now()->toDateString(),
            'payment_method' => 'eft',
            'external_reference' => 'LIGHTING-PAYMENT-TEST',
            'bank_reference' => null,
            'source' => 'manual',
        ]);
        $payment = app(PaymentService::class)->confirm($admin, $payment);
        $allocation = app(PaymentService::class)->allocate($admin, $payment, $invoice, $invoice->total_amount, 'lighting-payment-allocation');
        app(PaymentService::class)->allocate($admin, $payment->fresh(), $invoice->fresh(), $invoice->total_amount, 'lighting-payment-allocation');

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertSame('scheduled', $reservation->fresh()->status);
        $this->assertSame(1, LightingScheduleInterval::whereHas('reservations', fn ($query) => $query->whereKey($reservation->id))->where('status', 'planned')->count());
        $this->assertSame(2, LightingCommand::where('lighting_reservation_id', $reservation->id)->orWhereHas('interval.reservations', fn ($query) => $query->whereKey($reservation->id))->count());

        app(PaymentService::class)->reverseAllocation($admin, $allocation, 'Acceptance reversal');

        $this->assertSame('pending_payment', $booking->fresh()->status);
        $this->assertSame('unpaid', $booking->fresh()->payment_status);
        $this->assertSame('pending_payment', $reservation->fresh()->status);
        $this->assertSame(0, LightingScheduleInterval::whereHas('reservations', fn ($query) => $query->whereKey($reservation->id))->where('status', 'planned')->count());
        $this->assertSame(2, LightingCommand::whereHas('interval.reservations', fn ($query) => $query->whereKey($reservation->id))->where('status', 'superseded')->count());

        app(PaymentService::class)->allocate($admin, $payment->fresh(), $invoice->fresh(), $invoice->total_amount, 'lighting-payment-reallocation');

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('scheduled', $reservation->fresh()->status);
        $this->assertSame(1, LightingScheduleInterval::whereHas('reservations', fn ($query) => $query->whereKey($reservation->id))->where('status', 'planned')->count());
        $this->assertSame(2, LightingCommand::whereHas('interval.reservations', fn ($query) => $query->whereKey($reservation->id))->where('status', 'pending')->count());
        $this->assertSame(2, LightingCommand::whereHas('interval.reservations', fn ($query) => $query->whereKey($reservation->id))->count());
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
        $this->assertSame('on', $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->actual_on_at);
        $this->assertSame(0, $second['examined']);

        $off = $command->interval->commands()->where('command_type', 'switch_off')->firstOrFail();
        $off->update(['due_at' => now()]);
        $offResult = app(LightingCommandDispatcher::class)->dispatch();
        $this->assertSame(1, $offResult['succeeded']);
        $this->assertSame('off', $reservation->fresh()->status);
        $this->assertNotNull($reservation->fresh()->actual_off_at);

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
        $response->assertJsonStructure(['zones' => [['future_reservations_count']]]);
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

    public function test_channel_zone_tariff_and_policy_mutations_are_scoped_and_persisted(): void
    {
        User::factory()->create(['role' => 'hsc_super_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'lighting-ops-admin')]);
        $device = LightingDevice::where('code', 'LIGHT-DEMO-DRY')->firstOrFail();
        $channel = $this->withToken('lighting-ops-admin')->postJson("/api/lighting/devices/{$device->id}/channels", [
            'channel_number' => 2, 'name' => 'Acceptance channel', 'enabled' => true, 'maximum_continuous_on_minutes' => 180,
        ])->assertCreated()->json();
        $this->withToken('lighting-ops-admin')->postJson("/api/lighting/devices/{$device->id}/channels", [
            'channel_number' => 2, 'name' => 'Duplicate', 'enabled' => true, 'maximum_continuous_on_minutes' => 180,
        ])->assertUnprocessable();
        $this->withToken('lighting-ops-admin')->putJson("/api/lighting/devices/{$device->id}/channels/{$channel['id']}", [
            'channel_number' => 2, 'name' => 'Acceptance channel updated', 'enabled' => false, 'maximum_continuous_on_minutes' => 120,
        ])->assertOk()->assertJsonPath('enabled', false);

        $tennis = \App\Models\SportCode::where('slug', 'tennis')->firstOrFail();
        $padel = \App\Models\SportCode::where('slug', 'padel')->firstOrFail();
        $facility = \App\Models\Facility::where('code', 'TEN-3')->firstOrFail();
        $venue = $facility->venue;
        $zonePayload = ['venue_id' => $venue->id, 'sport_code_id' => $tennis->id, 'lighting_device_channel_id' => $channel['id'], 'name' => 'Acceptance zone', 'code' => 'LIGHT-ACCEPTANCE-ZONE', 'active' => true, 'automatic_control_enabled' => false, 'provider_mode' => 'dry_run', 'pre_start_minutes' => 10, 'post_end_minutes' => 5, 'minimum_billable_minutes' => 30, 'billing_increment_minutes' => 15, 'maximum_continuous_on_minutes' => 120, 'financial_owner_type' => 'sport_code', 'facility_ids' => [$facility->id]];
        $this->withToken('lighting-ops-admin')->postJson('/api/lighting/zones', $zonePayload)->assertUnprocessable();
        $this->withToken('lighting-ops-admin')->putJson("/api/lighting/devices/{$device->id}/channels/{$channel['id']}", [
            'channel_number' => 2, 'name' => 'Acceptance channel updated', 'enabled' => true, 'maximum_continuous_on_minutes' => 120,
        ])->assertOk()->assertJsonPath('enabled', true);
        $zone = $this->withToken('lighting-ops-admin')->postJson('/api/lighting/zones', $zonePayload)->assertCreated()->json();
        $this->assertDatabaseHas('facility_lighting_zone', ['facility_id' => $facility->id, 'lighting_zone_id' => $zone['id']]);
        $this->withToken('lighting-ops-admin')->postJson('/api/lighting/zones', [...$zonePayload, 'sport_code_id' => $padel->id, 'name' => 'Conflict', 'code' => 'LIGHT-ACCEPTANCE-CONFLICT', 'facility_ids' => []])->assertUnprocessable();

        $this->withToken('lighting-ops-admin')->postJson("/api/lighting/zones/{$zone['id']}/tariffs", ['name' => 'Acceptance peak tariff', 'pricing_method' => 'hourly', 'amount_cents' => 7250, 'profile' => null, 'starts_at' => '18:00', 'ends_at' => '22:00', 'minimum_minutes' => 30, 'increment_minutes' => 15, 'financial_owner_type' => 'sport_code', 'active' => true, 'priority' => 90])->assertCreated();
        $this->withToken('lighting-ops-admin')->postJson("/api/lighting/zones/{$zone['id']}/policies", ['strategy' => 'fixed_band', 'starts_at' => '18:00', 'ends_at' => '22:00', 'profile' => null, 'active' => true, 'priority' => 90])->assertCreated();
        $this->assertDatabaseHas('lighting_tariffs', ['lighting_zone_id' => $zone['id'], 'amount_cents' => 7250, 'priority' => 90]);
        $this->assertDatabaseHas('lighting_requirement_policies', ['lighting_zone_id' => $zone['id'], 'strategy' => 'fixed_band', 'priority' => 90]);
    }

    public function test_dry_run_failures_reconciliation_override_and_emergency_are_safe_and_idempotent(): void
    {
        User::factory()->create(['role' => 'hsc_super_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'lighting-simulation-admin')]);
        LightingSetting::whereNull('sport_code_id')->update(['automation_enabled' => true, 'dry_run' => true, 'retry_count' => 2, 'retry_delay_seconds' => 1]);
        $device = LightingDevice::where('code', 'LIGHT-DEMO-DRY')->firstOrFail();
        $reservation = LightingReservation::firstOrFail();
        $reservation->update(['status' => 'scheduled']);
        app(LightingSchedulePlanner::class)->planChannel($reservation->zone->lighting_device_channel_id);
        $command = LightingCommand::where('command_type', 'switch_on')->firstOrFail();
        $command->update(['due_at' => now(), 'next_attempt_at' => null, 'status' => 'pending']);

        $this->withToken('lighting-simulation-admin')->putJson("/api/lighting/devices/{$device->id}/simulation", ['outcome' => 'retryable_timeout'])->assertOk();
        $this->withToken('lighting-simulation-admin')->postJson('/api/lighting/actions/dispatch')->assertOk()->assertJsonPath('retried', 1);
        $this->assertSame('retryable_failure', $command->fresh()->status);
        $this->withToken('lighting-simulation-admin')->postJson('/api/lighting/actions/dispatch')->assertOk()->assertJsonPath('examined', 0);
        $command->update(['next_attempt_at' => now()]);
        $this->withToken('lighting-simulation-admin')->postJson('/api/lighting/actions/dispatch')->assertOk()->assertJsonPath('failed', 1);
        $this->assertSame('permanent_failure', $command->fresh()->status);
        $this->assertSame(1, \App\Models\LightingAlert::where('alert_type', 'dry_run_unreachable')->where('status', 'open')->count());

        $channel = $reservation->zone->channel;
        $this->withToken('lighting-simulation-admin')->putJson("/api/lighting/devices/{$device->id}/simulation", ['outcome' => 'unexpected_on'])->assertOk();
        $this->withToken('lighting-simulation-admin')->postJson('/api/lighting/actions/reconcile')->assertOk();
        $this->assertDatabaseHas('lighting_alerts', ['source_id' => $channel->id, 'alert_type' => 'state_mismatch', 'status' => 'open']);

        $invoiceTotal = $reservation->booking->invoice?->total_amount;
        $override = $this->withToken('lighting-simulation-admin')->postJson("/api/lighting/channels/{$channel->id}/override", ['state' => 'on', 'minutes' => 30, 'reason' => 'Acceptance override'])->assertCreated()->json();
        $this->assertDatabaseHas('lighting_manual_overrides', ['id' => $override['id'], 'reason' => 'Acceptance override']);
        $overrideCommand = LightingCommand::where('idempotency_key', "manual-override:{$override['id']}")->firstOrFail();
        $overrideResult = app(LightingCommandDispatcher::class)->dispatch();
        $this->assertSame(1, $overrideResult['succeeded']);
        $this->assertSame('succeeded', $overrideCommand->fresh()->status);
        $this->assertSame('dry_run_on', $overrideCommand->fresh()->provider_result_code);
        $this->assertSame('on', $channel->fresh()->last_known_state);
        $this->withToken('lighting-simulation-admin')->postJson("/api/lighting/channels/{$channel->id}/override", ['state' => 'on', 'minutes' => 30, 'reason' => ''])->assertUnprocessable();
        $pending = LightingCommand::create(['command_uuid' => (string) Str::uuid(), 'lighting_device_channel_id' => $channel->id, 'command_type' => 'switch_on', 'due_at' => now()->addMinute(), 'status' => 'pending', 'idempotency_key' => 'acceptance-emergency-pending']);
        $key = (string) Str::uuid();
        $emergency = $this->withToken('lighting-simulation-admin')->postJson("/api/lighting/channels/{$channel->id}/emergency-off", ['reason' => 'Acceptance emergency', 'idempotency_key' => $key])->assertOk()->json();
        $repeated = $this->withToken('lighting-simulation-admin')->postJson("/api/lighting/channels/{$channel->id}/emergency-off", ['reason' => 'Acceptance emergency', 'idempotency_key' => $key])->assertOk()->json();
        $this->assertSame('superseded', $pending->fresh()->status);
        $this->assertSame('emergency_off', $emergency['command']['command_type']);
        $this->assertSame($emergency['command']['id'], $repeated['command']['id']);
        $this->assertSame($invoiceTotal, $reservation->booking->invoice?->fresh()->total_amount);
        $this->assertSame(1, \App\Models\LightingAlert::where('source_id', $channel->id)->where('alert_type', 'emergency_off')->where('status', 'open')->count());
    }
}
