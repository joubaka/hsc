<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class BookingCancelled
{
    use Dispatchable;

    public function __construct(public int $bookingId) {}
}
