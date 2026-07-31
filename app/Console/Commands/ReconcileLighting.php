<?php

namespace App\Console\Commands;

use App\Services\LightingOperationsService;
use Illuminate\Console\Command;

class ReconcileLighting extends Command
{
    protected $signature = 'lighting:reconcile';

    public function handle(LightingOperationsService $s): int
    {
        $this->table(array_keys($r = $s->reconcile()), [array_values($r)]);

        return self::SUCCESS;
    }
}
