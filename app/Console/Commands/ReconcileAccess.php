<?php

namespace App\Console\Commands;

use App\Models\BookingAccessGrant;
use App\Services\BookingAccessGrantService;
use Illuminate\Console\Command;

class ReconcileAccess extends Command
{
    protected $signature = 'access:reconcile';

    protected $description = 'Reconcile expired temporary access';

    public function handle(BookingAccessGrantService $s): int
    {
        $expired = BookingAccessGrant::with('booking')->whereIn('status', ['eligible', 'provisioned'])->where('valid_until', '<', now())->get();
        foreach ($expired as $g) {
            $s->revoke($g->booking, 'expired');
        }$this->info('expired='.$expired->count());

        return self::SUCCESS;
    }
}
