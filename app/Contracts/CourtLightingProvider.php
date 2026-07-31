<?php

namespace App\Contracts;

use App\Models\Booking;

interface CourtLightingProvider
{
    public function scheduleForBooking(Booking $booking): void;

    public function cancelForBooking(Booking $booking): void;
}
