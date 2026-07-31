<?php

namespace App\Console\Commands;

use App\Models\AccessEndpoint;
use App\Services\AccessProviderResolver;
use App\Support\AccessEndpointData;
use Illuminate\Console\Command;

class HealthCheckAccess extends Command
{
    protected $signature = 'access:health-check';

    protected $description = 'Check temporary access endpoints';

    public function handle(AccessProviderResolver $r): int
    {
        $c = ['examined' => 0, 'healthy' => 0, 'failed' => 0];
        foreach (AccessEndpoint::where('enabled', true)->get() as $e) {
            $c['examined']++;
            $x = $r->resolve($e)->healthCheck(AccessEndpointData::fromModel($e));
            $e->update(['last_health_status' => $x->success ? 'healthy' : 'failed', 'last_health_checked_at' => now(), 'failure_count' => $x->success ? 0 : $e->failure_count + 1]);
            $c[$x->success ? 'healthy' : 'failed']++;
        }$this->table(array_keys($c), [$c]);

        return self::SUCCESS;
    }
}
