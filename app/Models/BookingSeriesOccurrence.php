<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingSeriesOccurrence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_date' => 'date', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'preview_snapshot' => 'array'];
    }

    public function series()
    {
        return $this->belongsTo(BookingSeries::class, 'booking_series_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
