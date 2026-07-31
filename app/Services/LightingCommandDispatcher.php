<?php

namespace App\Services;

use App\Models\LightingAlert;
use App\Models\LightingCommand;
use App\Support\Audit;
use App\Support\LightingEndpoint;
use Illuminate\Support\Facades\DB;

class LightingCommandDispatcher
{
    public function dispatch(int $limit = 50): array
    {
        $counts = ['examined' => 0, 'succeeded' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];
        $ids = LightingCommand::whereIn('status', ['pending', 'retryable_failure'])->where('due_at', '<=', now())->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('due_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $command = DB::transaction(function () use ($id) {
                $c = LightingCommand::with(['channel.device', 'interval.reservations'])->lockForUpdate()->find($id);
                if (! $c || ! in_array($c->status, ['pending', 'retryable_failure'])) {
                    return null;
                }$c->update(['status' => 'processing', 'attempt_count' => $c->attempt_count + 1]);

                return $c;
            });
            if (! $command) {
                continue;
            }$counts['examined']++;
            $channel = $command->channel;
            $interval = $command->interval;
            $manualOverride = str_starts_with($command->idempotency_key, 'manual-override:');
            $invalid = $command->command_type === 'switch_on' && ! $manualOverride && (! $interval || $interval->ends_at->isPast() || $interval->status !== 'planned' || $interval->reservations->whereIn('status', ['scheduled', 'on'])->isEmpty());
            $tooLong = $interval && $interval->starts_at->diffInMinutes($interval->ends_at) > $channel->maximum_continuous_on_minutes;
            if ($invalid || $tooLong || ! $channel->enabled || ! $channel->device->enabled) {
                $command->update(['status' => 'cancelled', 'safe_error_code' => $tooLong ? 'maximum_on_exceeded' : 'command_obsolete', 'processed_at' => now()]);
                $counts['skipped']++;
                if ($tooLong) {
                    $this->alert($channel, 'maximum_on_exceeded', 'Maximum continuous-on protection blocked a command.');
                }

                continue;
            }
            if (! $manualOverride && $command->command_type !== 'emergency_off' && $channel->manual_lock_state && $channel->manual_lock_expires_at?->isFuture()) {
                $command->update(['status' => 'superseded', 'safe_error_code' => 'manual_override_active', 'processed_at' => now()]);
                $counts['skipped']++;

                continue;
            }
            $provider = app(LightingProviderResolver::class)->resolve($channel);
            $endpoint = LightingEndpoint::fromChannel($channel);
            $result = match ($command->command_type) {
                'switch_on' => $provider->switchOn($endpoint, $command->idempotency_key),'switch_off','emergency_off' => $provider->switchOff($endpoint, $command->idempotency_key),'health_check' => $provider->healthCheck($endpoint),default => $provider->readState($endpoint)
            };
            DB::transaction(function () use ($command, $result, $channel, &$counts) {
                $c = LightingCommand::lockForUpdate()->findOrFail($command->id);
                if ($result->success) {
                    $c->update(['status' => 'succeeded', 'provider_result_code' => $result->code, 'processed_at' => now()]);
                    $channel->update(['last_known_state' => $result->state ?? $channel->last_known_state, 'last_state_checked_at' => now()]);
                    if ($c->interval) {
                        if ($c->command_type === 'switch_on') {
                            $c->interval->reservations()->whereIn('lighting_reservations.status', ['scheduled', 'on'])->update([
                                'status' => 'on',
                                'actual_on_at' => now(),
                            ]);
                        } elseif ($c->command_type === 'switch_off') {
                            $c->interval->reservations()->whereIn('lighting_reservations.status', ['scheduled', 'on'])->update([
                                'status' => 'off',
                                'actual_off_at' => now(),
                            ]);
                        }
                    }
                    $counts['succeeded']++;
                } else {
                    $settings = \App\Models\LightingSetting::whereNull('sport_code_id')->first();
                    $retry = $result->retryable && $c->attempt_count < ($settings?->retry_count ?? 3);
                    $c->update(['status' => $retry ? 'retryable_failure' : 'permanent_failure', 'safe_error_code' => $result->code, 'next_attempt_at' => $retry ? now()->addSeconds($settings?->retry_delay_seconds ?? 60) : null, 'processed_at' => $retry ? null : now()]);
                    $counts[$retry ? 'retried' : 'failed']++;
                    if (! $retry) {
                        $this->alert($channel, $result->code, 'A lighting command requires administrator attention.');
                    }
                }
                Audit::record('lighting.command_processed', $c, [], ['status' => $c->status, 'safe_code' => $result->code]);
            });
        }

        return $counts;
    }

    private function alert($channel, string $type, string $message): void
    {
        LightingAlert::firstOrCreate(['source_type' => $channel::class, 'source_id' => $channel->id, 'alert_type' => $type, 'status' => 'open'], ['sport_code_id' => null, 'severity' => 'critical', 'safe_message' => $message]);
    }
}
