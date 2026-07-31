<?php

namespace App\Console\Commands;

use App\Services\NoShowProcessor;
use Illuminate\Console\Command;

class ProcessBookingNoShows extends Command
{
    protected $signature = 'bookings:process-no-shows {--limit=100}';

    protected $description = 'Process closed booking check-in windows';

    public function handle(NoShowProcessor $s): int
    {
        $this->table(['examined', 'marked', 'released'], [$s->process((int) $this->option('limit'))]);

        return self::SUCCESS;
    }
}
