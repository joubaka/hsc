<?php

namespace App\Services\LightingProviders;

use App\Contracts\CourtLightingProvider;
use App\Support\LightingCommandResult;
use App\Support\LightingEndpoint;

class DryRunLightingProvider implements CourtLightingProvider
{
    public function switchOn(LightingEndpoint $e, string $k): LightingCommandResult
    {
        if ($failure = $this->failure($e)) {
            return $failure;
        }

        return LightingCommandResult::success('dry_run_on', 'on');
    }

    public function switchOff(LightingEndpoint $e, string $k): LightingCommandResult
    {
        if ($failure = $this->failure($e)) {
            return $failure;
        }

        return LightingCommandResult::success('dry_run_off', 'off');
    }

    public function readState(LightingEndpoint $e): LightingCommandResult
    {
        if ($failure = $this->failure($e)) {
            return $failure;
        }
        $outcome = $e->settings['dry_run_outcome'] ?? 'success';

        return LightingCommandResult::success('dry_run_state', $outcome === 'unexpected_on' ? 'on' : 'off');
    }

    public function healthCheck(LightingEndpoint $e): LightingCommandResult
    {
        if ($failure = $this->failure($e)) {
            return $failure;
        }

        return LightingCommandResult::success('dry_run_healthy');
    }

    private function failure(LightingEndpoint $e): ?LightingCommandResult
    {
        return match ($e->settings['dry_run_outcome'] ?? 'success') {
            'retryable_timeout', 'unreachable' => LightingCommandResult::failure('dry_run_unreachable', true),
            'permanent_authentication' => LightingCommandResult::failure('dry_run_authentication_failed'),
            'invalid_response' => LightingCommandResult::failure('dry_run_invalid_response'),
            default => null,
        };
    }
}
