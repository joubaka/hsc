<?php

namespace App\Services;

use App\Models\AccessAlert;
use App\Models\AccessCommand;
use App\Support\AccessEndpointData;
use Illuminate\Support\Facades\DB;

class AccessCommandDispatcher
{
    public function dispatch(int $limit = 50): array
    {
        $counts = ['examined' => 0, 'succeeded' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];
        $ids = AccessCommand::whereIn('status', ['pending', 'retryable_failure'])->where('due_at', '<=', now())->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderByRaw("CASE WHEN command_type='revoke' THEN 0 ELSE 1 END")->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $command = AccessCommand::with(['grant.booking', 'endpoint'])->find($id);
            if (! $command) {
                continue;
            }$counts['examined']++;
            $grant = $command->grant;
            if (! $command->endpoint || ! $command->endpoint->enabled || ($command->command_type === 'provision' && (! $grant || $grant->valid_until->isPast() || in_array($grant->booking->status, ['cancelled', 'released_no_show'], true)))) {
                $command->update(['status' => 'superseded', 'safe_error_code' => 'access_command_stale', 'processed_at' => now()]);
                $counts['skipped']++;

                continue;
            }$command->increment('attempt_count');
            $provider = app(AccessProviderResolver::class)->resolve($command->endpoint);
            $endpoint = AccessEndpointData::fromModel($command->endpoint);
            $result = $command->command_type === 'revoke' ? $provider->revoke($endpoint, $grant, $command->idempotency_key) : $provider->provision($endpoint, $grant, $command->idempotency_key);
            DB::transaction(function () use ($command, $grant, $result, &$counts) {
                if ($result->success) {
                    $command->update(['status' => 'succeeded', 'provider_result_code' => $result->code, 'processed_at' => now()]);
                    $grant->update($command->command_type === 'revoke' ? ['status' => 'revoked', 'revoked_at' => now()] : ['status' => 'provisioned', 'provisioned_at' => now()]);
                    $counts['succeeded']++;
                } else {
                    $retry = $result->retryable && $command->attempt_count < 3;
                    $command->update(['status' => $retry ? 'retryable_failure' : 'permanent_failure', 'safe_error_code' => $result->code, 'next_attempt_at' => $retry ? now()->addMinute() : null]);
                    $counts[$retry ? 'retried' : 'failed']++;
                    if (! $retry) {
                        AccessAlert::firstOrCreate(['source_type' => $grant::class, 'source_id' => $grant->id, 'alert_type' => 'provisioning_failed', 'status' => 'open'], ['sport_code_id' => $grant->booking->sport_code_id, 'severity' => 'critical', 'safe_message' => 'Temporary booking access requires administrator attention.']);
                    }
                }
            });
        }

        return $counts;
    }
}
