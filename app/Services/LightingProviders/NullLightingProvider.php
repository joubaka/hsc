<?php

namespace App\Services\LightingProviders;

use App\Contracts\CourtLightingProvider;
use App\Support\LightingCommandResult;
use App\Support\LightingEndpoint;

class NullLightingProvider implements CourtLightingProvider
{
    private function no(): LightingCommandResult
    {
        return LightingCommandResult::failure('automation_disabled');
    }

    public function switchOn(LightingEndpoint $e, string $k): LightingCommandResult
    {
        return $this->no();
    }

    public function switchOff(LightingEndpoint $e, string $k): LightingCommandResult
    {
        return $this->no();
    }

    public function readState(LightingEndpoint $e): LightingCommandResult
    {
        return $this->no();
    }

    public function healthCheck(LightingEndpoint $e): LightingCommandResult
    {
        return $this->no();
    }
}
