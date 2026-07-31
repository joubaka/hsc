<?php

namespace App\Console\Commands;

use App\Services\LightingOperationsService;
use Illuminate\Console\Command;

class HealthCheckLighting extends Command
{
    protected $signature = 'lighting:health-check';

    public function handle(LightingOperationsService $s): int
    {
        $this->table(array_keys($r = $s->health()), [array_values($r)]);

        return $r['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
