<?php

namespace App\Services\LightingProviders;

use App\Contracts\CourtLightingProvider;
use App\Support\LightingCommandResult;
use App\Support\LightingEndpoint;

class DryRunLightingProvider implements CourtLightingProvider
{
    public function switchOn(LightingEndpoint $e, string $k): LightingCommandResult
    {
        return LightingCommandResult::success('dry_run_on', 'on');
    }

    public function switchOff(LightingEndpoint $e, string $k): LightingCommandResult
    {
        return LightingCommandResult::success('dry_run_off', 'off');
    }

    public function readState(LightingEndpoint $e): LightingCommandResult
    {
        return LightingCommandResult::success('dry_run_state', 'off');
    }

    public function healthCheck(LightingEndpoint $e): LightingCommandResult
    {
        return LightingCommandResult::success('dry_run_healthy');
    }
}
