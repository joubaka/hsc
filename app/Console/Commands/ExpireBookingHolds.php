<?php

namespace App\Console\Commands;

use App\Models\BookingHold;
use Illuminate\Console\Command;

class ExpireBookingHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Mark elapsed active booking holds as expired';

    public function handle(): int
    {
        $query = BookingHold::where('status', 'active')->where('expires_at', '<=', now());
        $examined = (clone $query)->count();
        $expired = $query->update(['status' => 'expired', 'updated_at' => now()]);

        $this->info("Booking holds examined: {$examined}; expired: {$expired}; failed: 0");

        return self::SUCCESS;
    }
}
