<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingSeriesMutation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['request_snapshot' => 'array', 'result_snapshot' => 'array'];
    }

    public function series()
    {
        return $this->belongsTo(BookingSeries::class, 'booking_series_id');
    }

    public function occurrence()
    {
        return $this->belongsTo(BookingSeriesOccurrence::class, 'booking_series_occurrence_id');
    }
}
