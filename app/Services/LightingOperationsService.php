<?php

namespace App\Services;

use App\Models\LightingAlert;
use App\Models\LightingCommand;
use App\Models\LightingDevice;
use App\Models\LightingDeviceChannel;
use App\Models\LightingManualOverride;
use App\Models\User;
use App\Support\Audit;
use App\Support\LightingEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LightingOperationsService
{
    public function health(): array
    {
        $c = ['examined' => 0, 'healthy' => 0, 'failed' => 0];
        foreach (LightingDevice::with('channels')->where('enabled', true)->get() as $d) {
            foreach ($d->channels->where('enabled', true) as $ch) {
                $c['examined']++;
                $r = app(LightingProviderResolver::class)->resolve($ch)->healthCheck(LightingEndpoint::fromChannel($ch));
                $d->update(['last_health_status' => $r->success ? 'healthy' : 'failed', 'last_health_checked_at' => now(), 'last_seen_at' => $r->success ? now() : $d->last_seen_at, 'last_error_code' => $r->success ? null : $r->code, 'failure_count' => $r->success ? 0 : $d->failure_count + 1]);
                $c[$r->success ? 'healthy' : 'failed']++;
            }
        }

        return $c;
    }

    public function reconcile(): array
    {
        $c = ['examined' => 0, 'matched' => 0, 'mismatched' => 0, 'failed' => 0];
        foreach (LightingDeviceChannel::with('device')->where('enabled', true)->get() as $ch) {
            $c['examined']++;
            $r = app(LightingProviderResolver::class)->resolve($ch)->readState(LightingEndpoint::fromChannel($ch));
            if (! $r->success) {
                $c['failed']++;

                continue;
            }$intended = $ch->manual_lock_state ?? ($ch->commands()->where('status', 'succeeded')->latest('processed_at')->value('command_type') === 'switch_on' ? 'on' : 'off');
            $ch->update(['last_known_state' => $r->state, 'last_state_checked_at' => now()]);
            if ($r->state === $intended) {
                $c['matched']++;
                LightingAlert::where(['source_type' => $ch::class, 'source_id' => $ch->id, 'alert_type' => 'state_mismatch', 'status' => 'open'])->update(['status' => 'resolved', 'resolved_at' => now()]);
            } else {
                $c['mismatched']++;
                LightingAlert::firstOrCreate(['source_type' => $ch::class, 'source_id' => $ch->id, 'alert_type' => 'state_mismatch', 'status' => 'open'], ['severity' => 'critical', 'safe_message' => 'Observed lighting state does not match the planned state.']);
            }
        }

        return $c;
    }

    public function override(User $actor, LightingDeviceChannel $channel, string $state, int $minutes, string $reason): LightingManualOverride
    {
        return DB::transaction(function () use ($actor, $channel, $state, $minutes, $reason) {
            $channel = LightingDeviceChannel::lockForUpdate()->findOrFail($channel->id);
            $o = LightingManualOverride::create(['lighting_device_channel_id' => $channel->id, 'state' => $state, 'starts_at' => now(), 'expires_at' => now()->addMinutes($minutes), 'reason' => $reason, 'created_by_user_id' => $actor->id]);
            $channel->update(['manual_lock_state' => $state, 'manual_lock_expires_at' => $o->expires_at]);
            LightingCommand::create(['command_uuid' => (string) Str::uuid(), 'lighting_device_channel_id' => $channel->id, 'command_type' => $state === 'on' ? 'switch_on' : 'switch_off', 'due_at' => now(), 'status' => 'pending', 'idempotency_key' => "manual-override:{$o->id}"]);
            Audit::record('lighting.manual_override', $o, [], ['channel_id' => $channel->id, 'state' => $state, 'expires_at' => $o->expires_at, 'reason' => $reason]);

            return $o;
        });
    }

    public function emergencyOff(User $actor, LightingDeviceChannel $channel, string $reason, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($channel, $reason, $idempotencyKey) {
            $channel = LightingDeviceChannel::lockForUpdate()->findOrFail($channel->id);
            $existing = $channel->commands()->where('idempotency_key', "emergency-off:{$idempotencyKey}")->first();
            if ($existing) {
                return ['command' => $existing, 'superseded_switch_ons' => 0, 'affected_bookings' => collect()];
            }
            $superseded = $channel->commands()->where('command_type', 'switch_on')->whereIn('status', ['pending', 'retryable_failure'])->update(['status' => 'superseded']);
            $command = LightingCommand::create(['command_uuid' => (string) Str::uuid(), 'lighting_device_channel_id' => $channel->id, 'command_type' => 'emergency_off', 'due_at' => now(), 'status' => 'pending', 'idempotency_key' => "emergency-off:{$idempotencyKey}"]);
            LightingAlert::firstOrCreate(['source_type' => $channel::class, 'source_id' => $channel->id, 'alert_type' => 'emergency_off', 'status' => 'open'], ['severity' => 'critical', 'safe_message' => 'Emergency lighting switch-off was activated.']);
            Audit::record('lighting.emergency_off', $command, [], ['channel_id' => $channel->id, 'reason' => $reason, 'superseded_switch_ons' => $superseded]);

            return ['command' => $command, 'superseded_switch_ons' => $superseded, 'affected_bookings' => $channel->id ? \App\Models\LightingReservation::whereHas('zone', fn ($q) => $q->where('lighting_device_channel_id', $channel->id))->whereIn('status', ['scheduled', 'on'])->pluck('booking_id') : collect()];
        });
    }
}
