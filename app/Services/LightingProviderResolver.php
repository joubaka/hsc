<?php

namespace App\Services;

use App\Contracts\CourtLightingProvider;
use App\Models\LightingDeviceChannel;
use App\Models\LightingSetting;

class LightingProviderResolver
{
    public function resolve(LightingDeviceChannel $channel): CourtLightingProvider
    {
        $d = $channel->device;
        $s = LightingSetting::whereNull('sport_code_id')->first();
        if (! $s?->automation_enabled) {
            return app(\App\Services\LightingProviders\NullLightingProvider::class);
        }
        if ($s->dry_run || $d->dry_run) {
            return app(\App\Services\LightingProviders\DryRunLightingProvider::class);
        }

        return match ($d->provider) {
            'manual' => app(\App\Services\LightingProviders\ManualLightingProvider::class),'shelly' => app(\App\Services\LightingProviders\ShellyLightingProvider::class),default => app(\App\Services\LightingProviders\NullLightingProvider::class)
        };
    }
}
