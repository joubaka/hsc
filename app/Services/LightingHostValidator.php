<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class LightingHostValidator
{
    public function validate(?string $host): ?string
    {
        if ($host === null || $host === '') {
            return null;
        }
        $host = trim(strtolower($host));
        if (str_contains($host, '://') || str_contains($host, '/') || str_contains($host, '?') || str_contains($host, '#') || str_contains($host, '@')) {
            throw ValidationException::withMessages(['network_host' => 'Enter only a verified local host name or private IP address.']);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $public = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public !== false) {
                throw ValidationException::withMessages(['network_host' => 'Only verified local-network addresses are allowed.']);
            }

            return $host;
        }
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{0,251}\\.local$/', $host)) {
            throw ValidationException::withMessages(['network_host' => 'Local device names must end in .local.']);
        }

        return $host;
    }
}
