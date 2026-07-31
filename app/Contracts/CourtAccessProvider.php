<?php

namespace App\Contracts;

use App\Models\Booking;

interface CourtAccessProvider
{
    public function provisionForBooking(Booking $booking): void;

    public function revokeForBooking(Booking $booking): void;
}
