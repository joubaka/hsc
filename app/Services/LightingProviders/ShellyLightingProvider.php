<?php

namespace App\Services\LightingProviders;

use App\Contracts\CourtLightingProvider;
use App\Services\LightingHostValidator;
use App\Support\LightingCommandResult;
use App\Support\LightingEndpoint;
use Illuminate\Support\Facades\Http;

class ShellyLightingProvider implements CourtLightingProvider
{
    public function __construct(private LightingHostValidator $hosts) {}

    public function switchOn(LightingEndpoint $e, string $k): LightingCommandResult
    {
        return $this->call($e, 'Switch.Set', ['id' => $e->channelNumber, 'on' => true], 'on');
    }

    public function switchOff(LightingEndpoint $e, string $k): LightingCommandResult
    {
        return $this->call($e, 'Switch.Set', ['id' => $e->channelNumber, 'on' => false], 'off');
    }

    public function readState(LightingEndpoint $e): LightingCommandResult
    {
        return $this->call($e, 'Switch.GetStatus', ['id' => $e->channelNumber]);
    }

    public function healthCheck(LightingEndpoint $e): LightingCommandResult
    {
        return $this->call($e, 'Shelly.GetDeviceInfo', []);
    }

    private function call(LightingEndpoint $e, string $method, array $params, ?string $state = null): LightingCommandResult
    {
        $host = $this->hosts->validate($e->host);
        if (! $host) {
            return LightingCommandResult::failure('host_not_configured');
        }
        $url = ($e->tls ? 'https' : 'http').'://'.$host.($e->port ? ":{$e->port}" : '')."/rpc/{$method}";
        $start = microtime(true);
        try {
            $request = Http::asJson()->timeout(5)->connectTimeout(3)->withOptions(['allow_redirects' => false]);
            if ($e->username && $e->password) {
                $request = $request->withOptions(['auth' => [$e->username, $e->password, 'digest']]);
            }
            $response = $request->post($url, $params);
            $latency = (int) ((microtime(true) - $start) * 1000);
            if ($response->redirect()) {
                return LightingCommandResult::failure('redirect_blocked');
            }
            if ($response->status() === 401) {
                return LightingCommandResult::failure('authentication_failed');
            }
            if (! $response->successful()) {
                return LightingCommandResult::failure($response->serverError() ? 'device_unreachable' : 'device_rejected', $response->serverError());
            }
            $json = $response->json();
            if (! is_array($json) || isset($json['error'])) {
                return LightingCommandResult::failure('invalid_response');
            }
            $observed = $state ?? (isset($json['output']) ? ($json['output'] ? 'on' : 'off') : null);

            return LightingCommandResult::success('shelly_ok', $observed, $latency);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return LightingCommandResult::failure('device_unreachable', true);
        } catch (\Throwable) {
            return LightingCommandResult::failure('provider_error');
        }
    }
}
