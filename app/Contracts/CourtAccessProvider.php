<?php

namespace App\Contracts;

use App\Models\BookingAccessGrant;
use App\Support\AccessEndpointData;
use App\Support\AccessProviderResult;

interface CourtAccessProvider
{
    public function provision(AccessEndpointData $endpoint, BookingAccessGrant $grant, string $idempotencyKey): AccessProviderResult;

    public function revoke(AccessEndpointData $endpoint, BookingAccessGrant $grant, string $idempotencyKey): AccessProviderResult;

    public function readState(AccessEndpointData $endpoint, BookingAccessGrant $grant): AccessProviderResult;

    public function healthCheck(AccessEndpointData $endpoint): AccessProviderResult;
}
