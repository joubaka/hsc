<?php

namespace App\Console\Commands;

use App\Services\AccessCommandDispatcher;
use Illuminate\Console\Command;

class DispatchAccessCommands extends Command
{
    protected $signature = 'access:dispatch-due';

    protected $description = 'Dispatch due temporary access commands';

    public function handle(AccessCommandDispatcher $s): int
    {
        $r = $s->dispatch();
        $this->table(array_keys($r), [$r]);

        return self::SUCCESS;
    }
}
