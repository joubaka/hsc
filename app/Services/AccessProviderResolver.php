<?php

namespace App\Services;

use App\Contracts\CourtAccessProvider;
use App\Models\AccessEndpoint;
use App\Services\AccessProviders\DryRunAccessProvider;
use App\Services\AccessProviders\ManualAccessProvider;
use App\Services\AccessProviders\NullAccessProvider;

class AccessProviderResolver
{
    public function resolve(AccessEndpoint $endpoint): CourtAccessProvider
    {
        return match (true) {
            $endpoint->dry_run => app(DryRunAccessProvider::class),$endpoint->provider === 'manual' => app(ManualAccessProvider::class),default => app(NullAccessProvider::class)
        };
    }
}
