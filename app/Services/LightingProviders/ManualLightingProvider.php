<?php

namespace App\Services\LightingProviders;

use App\Contracts\CourtLightingProvider;
use App\Support\LightingCommandResult;
use App\Support\LightingEndpoint;

class ManualLightingProvider implements CourtLightingProvider
{
    private function task(): LightingCommandResult
    {
        return LightingCommandResult::success('manual_action_required');
    }

    public function switchOn(LightingEndpoint $e, string $k): LightingCommandResult
    {
        return $this->task();
    }

    public function switchOff(LightingEndpoint $e, string $k): LightingCommandResult
    {
        return $this->task();
    }

    public function readState(LightingEndpoint $e): LightingCommandResult
    {
        return LightingCommandResult::success('manual_state', 'unknown');
    }

    public function healthCheck(LightingEndpoint $e): LightingCommandResult
    {
        return LightingCommandResult::success('manual_provider');
    }
}
