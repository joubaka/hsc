<?php

namespace App\Services\AccessProviders;

use App\Contracts\CourtAccessProvider;
use App\Models\BookingAccessGrant;
use App\Support\AccessEndpointData;
use App\Support\AccessProviderResult;

class NullAccessProvider implements CourtAccessProvider
{
    public function provision(AccessEndpointData $e, BookingAccessGrant $g, string $k): AccessProviderResult
    {
        return new AccessProviderResult(true, 'null_noop');
    }

    public function revoke(AccessEndpointData $e, BookingAccessGrant $g, string $k): AccessProviderResult
    {
        return new AccessProviderResult(true, 'null_noop');
    }

    public function readState(AccessEndpointData $e, BookingAccessGrant $g): AccessProviderResult
    {
        return new AccessProviderResult(true, 'null_unknown', false, 'unknown');
    }

    public function healthCheck(AccessEndpointData $e): AccessProviderResult
    {
        return new AccessProviderResult(true, 'null_healthy');
    }
}
