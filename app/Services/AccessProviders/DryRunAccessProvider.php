<?php

namespace App\Services\AccessProviders;

use App\Contracts\CourtAccessProvider;
use App\Models\BookingAccessGrant;
use App\Support\AccessEndpointData;
use App\Support\AccessProviderResult;

class DryRunAccessProvider implements CourtAccessProvider
{
    public function provision(AccessEndpointData $e, BookingAccessGrant $g, string $k): AccessProviderResult
    {
        return new AccessProviderResult(true, 'dry_run_provisioned', false, 'active');
    }

    public function revoke(AccessEndpointData $e, BookingAccessGrant $g, string $k): AccessProviderResult
    {
        return new AccessProviderResult(true, 'dry_run_revoked', false, 'revoked');
    }

    public function readState(AccessEndpointData $e, BookingAccessGrant $g): AccessProviderResult
    {
        return new AccessProviderResult(true, 'dry_run_state', false, $g->status === 'provisioned' ? 'active' : 'revoked');
    }

    public function healthCheck(AccessEndpointData $e): AccessProviderResult
    {
        return new AccessProviderResult(true, 'dry_run_healthy');
    }
}
