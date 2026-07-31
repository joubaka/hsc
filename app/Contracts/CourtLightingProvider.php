<?php

namespace App\Contracts;

use App\Support\LightingCommandResult;
use App\Support\LightingEndpoint;

interface CourtLightingProvider
{
    public function switchOn(LightingEndpoint $endpoint, string $idempotencyKey): LightingCommandResult;

    public function switchOff(LightingEndpoint $endpoint, string $idempotencyKey): LightingCommandResult;

    public function readState(LightingEndpoint $endpoint): LightingCommandResult;

    public function healthCheck(LightingEndpoint $endpoint): LightingCommandResult;
}
