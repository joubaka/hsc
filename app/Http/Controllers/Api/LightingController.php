<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LightingOperationRequest;
use App\Http\Requests\StoreLightingConfigurationRequest;
use App\Http\Requests\StoreLightingDeviceRequest;
use App\Http\Requests\StoreLightingZoneRequest;
use App\Models\LightingAlert;
use App\Models\LightingCommand;
use App\Models\LightingDevice;
use App\Models\LightingDeviceChannel;
use App\Models\LightingReservation;
use App\Models\LightingScheduleInterval;
use App\Models\LightingSetting;
use App\Models\LightingTariff;
use App\Models\LightingZone;
use App\Services\LightingHostValidator;
use App\Services\LightingOperationsService;
use App\Services\LightingProviderResolver;
use App\Support\Audit;
use App\Support\LightingEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LightingController extends Controller
{
    public function dashboard(Request $r): JsonResponse
    {
        $this->authorize('viewAny', LightingZone::class);
        $sports = $r->user()->isCentralAdministrator() ? null : $r->user()->sportCodes()->pluck('sport_codes.id');
        $zones = LightingZone::with(['facilities:id,name,code', 'channel.device:id,name,code,provider,enabled,dry_run,last_health_status'])->when($sports, fn ($q) => $q->whereIn('sport_code_id', $sports))->get();

        return response()->json(['settings' => $r->user()->isCentralAdministrator() ? LightingSetting::firstOrCreate(['sport_code_id' => null]) : null, 'devices' => $r->user()->isCentralAdministrator() ? LightingDevice::with('channels')->get() : [], 'zones' => $zones, 'reservations' => LightingReservation::with('booking:id,booking_reference')->whereHas('zone', fn ($q) => $sports ? $q->whereIn('sport_code_id', $sports) : $q)->latest()->limit(100)->get(), 'intervals' => LightingScheduleInterval::with(['channel.device:id,name,code', 'reservations.booking:id,booking_reference'])->latest('starts_at')->limit(100)->get(), 'commands' => LightingCommand::with('channel.device:id,name,code')->latest()->limit(100)->get(), 'alerts' => LightingAlert::when($sports, fn ($q) => $q->whereIn('sport_code_id', $sports))->latest()->limit(100)->get()]);
    }

    public function settings(StoreLightingConfigurationRequest $r): JsonResponse
    {
        $data = $r->validated();
        if (($data['automation_enabled'] ?? false) && ! ($data['dry_run'] ?? true)) {
            abort_unless($r->user()->isSuperAdministrator() && ($data['live_confirmation'] ?? '') === 'ENABLE LIVE LIGHTING', 422, 'Live automation requires the exact safety confirmation.');
        }unset($data['live_confirmation']);
        $s = LightingSetting::firstOrCreate(['sport_code_id' => null]);
        $old = $s->getAttributes();
        $s->update($data);
        Audit::record('lighting.settings_changed', $s, $old, collect($data)->except([])->all());

        return response()->json($s);
    }

    public function storeDevice(StoreLightingDeviceRequest $r, LightingHostValidator $hosts): JsonResponse
    {
        $d = $r->validated();
        $this->guardLiveDevice($r, $d);
        unset($d['live_confirmation']);
        $d['network_host'] = $hosts->validate($d['network_host'] ?? null);
        $d['created_by_user_id'] = $r->user()->id;
        $device = LightingDevice::create($d);
        Audit::record('lighting.device_created', $device, [], collect($d)->except(['username', 'password', 'provider_settings'])->all());

        return response()->json($device, 201);
    }

    public function updateDevice(StoreLightingDeviceRequest $r, LightingDevice $lightingDevice, LightingHostValidator $hosts): JsonResponse
    {
        $this->authorize('update', $lightingDevice);
        $d = $r->validated();
        $this->guardLiveDevice($r, $d);
        unset($d['live_confirmation']);
        $d['network_host'] = $hosts->validate($d['network_host'] ?? null);
        foreach (['username', 'password', 'provider_settings'] as $secret) {
            if (! array_key_exists($secret, $d) || blank($d[$secret])) {
                unset($d[$secret]);
            }
        }$old = $lightingDevice->only(['name', 'code', 'provider', 'enabled', 'dry_run']);
        $lightingDevice->update($d);
        Audit::record('lighting.device_updated', $lightingDevice, $old, $lightingDevice->only(array_keys($old)));

        return response()->json($lightingDevice);
    }

    public function channel(Request $r, LightingDevice $lightingDevice): JsonResponse
    {
        $this->authorize('update', $lightingDevice);
        $d = $r->validate(['channel_number' => 'required|integer|between:0,15', 'name' => 'required|string|max:120', 'enabled' => 'boolean', 'maximum_continuous_on_minutes' => 'integer|between:15,480']);
        $channel = $lightingDevice->channels()->updateOrCreate(['channel_number' => $d['channel_number']], $d);

        return response()->json($channel, 201);
    }

    public function test(LightingDevice $lightingDevice): JsonResponse
    {
        $this->authorize('operate', $lightingDevice);
        $channel = $lightingDevice->channels()->where('enabled', true)->firstOrFail();
        $start = microtime(true);
        $result = app(LightingProviderResolver::class)->resolve($channel)->healthCheck(LightingEndpoint::fromChannel($channel));

        return response()->json(['success' => $result->success, 'status' => $result->code, 'provider' => $lightingDevice->provider, 'channel_count' => $lightingDevice->channels()->count(), 'latency_ms' => $result->latencyMs ?? (int) ((microtime(true) - $start) * 1000)]);
    }

    public function storeZone(StoreLightingZoneRequest $r): JsonResponse
    {
        $d = $r->validated();
        $facilities = $d['facility_ids'] ?? [];
        unset($d['facility_ids']);
        abort_unless($r->user()->canAccessSportCode($d['sport_code_id'], true), 403);
        $this->guardChannelMapping($d);
        $zone = LightingZone::create($d);
        $zone->facilities()->sync($facilities);
        Audit::record('lighting.zone_created', $zone, [], ['facility_ids' => $facilities]);

        return response()->json($zone->load('facilities'), 201);
    }

    public function updateZone(StoreLightingZoneRequest $r, LightingZone $lightingZone): JsonResponse
    {
        $d = $r->validated();
        $facilities = $d['facility_ids'] ?? [];
        unset($d['facility_ids']);
        $this->guardChannelMapping($d, $lightingZone);
        $lightingZone->update($d);
        $lightingZone->facilities()->sync($facilities);
        Audit::record('lighting.zone_updated', $lightingZone, [], ['facility_ids' => $facilities]);

        return response()->json($lightingZone->load('facilities'));
    }

    private function guardLiveDevice(Request $request, array $data): void
    {
        if (! ($data['dry_run'] ?? true)) {
            abort_unless(
                $request->user()->isSuperAdministrator()
                && ($data['live_confirmation'] ?? '') === 'ENABLE LIVE LIGHTING',
                422,
                'Live device control requires the exact safety confirmation.',
            );
        }
    }

    private function guardChannelMapping(array $data, ?LightingZone $ignore = null): void
    {
        if (! ($data['active'] ?? true) || empty($data['lighting_device_channel_id'])) {
            return;
        }

        $conflict = LightingZone::where('lighting_device_channel_id', $data['lighting_device_channel_id'])
            ->where('active', true)
            ->where('sport_code_id', '!=', $data['sport_code_id'])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();
        abort_if($conflict, 422, 'This channel is already mapped to an active zone for another sport code.');
    }

    public function tariff(Request $r, LightingZone $lightingZone): JsonResponse
    {
        $this->authorize('update', $lightingZone);
        $d = $r->validate(['name' => 'required|string|max:120', 'pricing_method' => 'required|in:fixed,hourly,per_minute', 'amount_cents' => 'required|integer|min:0|max:1000000', 'profile' => 'nullable|string|max:40', 'starts_at' => 'nullable|date_format:H:i', 'ends_at' => 'nullable|date_format:H:i', 'minimum_minutes' => 'integer|between:1,240', 'increment_minutes' => 'integer|between:1,60', 'financial_owner_type' => 'required|in:sport_code,central_hsc', 'active' => 'boolean', 'priority' => 'integer|between:0,1000']);
        $t = $lightingZone->tariffs()->create([...$d, 'version' => 1]);
        Audit::record('lighting.tariff_created', $t, [], collect($d)->except('amount_cents')->all() + ['amount_changed' => true]);

        return response()->json($t, 201);
    }

    public function policy(Request $r, LightingZone $lightingZone): JsonResponse
    {
        $this->authorize('update', $lightingZone);
        $d = $r->validate(['strategy' => 'required|in:manual,fixed_band,always_required,never_available', 'starts_at' => 'nullable|required_if:strategy,fixed_band|date_format:H:i', 'ends_at' => 'nullable|required_if:strategy,fixed_band|date_format:H:i', 'profile' => 'nullable|string|max:40', 'active' => 'boolean', 'priority' => 'integer|between:0,1000']);

        return response()->json($lightingZone->policies()->create([...$d, 'version' => 1]), 201);
    }

    public function override(LightingOperationRequest $r, LightingDeviceChannel $channel, LightingOperationsService $s): JsonResponse
    {
        return response()->json($s->override($r->user(), $channel, $r->validated('state'), $r->integer('minutes'), $r->validated('reason')), 201);
    }

    public function emergency(LightingOperationRequest $r, LightingDeviceChannel $channel, LightingOperationsService $s): JsonResponse
    {
        return response()->json($s->emergencyOff($r->user(), $channel, $r->validated('reason')));
    }

    public function retry(Request $r, LightingCommand $command): JsonResponse
    {
        abort_unless($r->user()->active && $r->user()->isCentralAdministrator(), 403);
        abort_unless(in_array($command->status, ['retryable_failure', 'permanent_failure'], true), 422);
        $command->update(['status' => 'pending', 'next_attempt_at' => now(), 'safe_error_code' => null]);

        return response()->json($command);
    }

    public function reports(Request $r): JsonResponse
    {
        $this->authorize('viewAny', LightingZone::class);

        return response()->json(['usage' => LightingReservation::selectRaw('lighting_zone_id, count(*) booking_count, sum(billable_minutes) scheduled_minutes, sum(amount) amount')->groupBy('lighting_zone_id')->get(), 'reliability' => LightingCommand::selectRaw('status,count(*) total')->groupBy('status')->pluck('total', 'status'), 'revenue' => LightingTariff::count()]);
    }
}
