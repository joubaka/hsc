<?php

namespace App\Console\Commands;

use App\Services\LightingCommandDispatcher;
use Illuminate\Console\Command;

class DispatchLightingCommands extends Command
{
    protected $signature = 'lighting:dispatch-due';

    protected $description = 'Dispatch due lighting commands safely';

    public function handle(LightingCommandDispatcher $s): int
    {
        $this->table(array_keys($r = $s->dispatch()), [array_values($r)]);

        return $r['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
