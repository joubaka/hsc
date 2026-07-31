<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LightingReservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_on_at' => 'datetime', 'scheduled_off_at' => 'datetime', 'actual_on_at' => 'datetime', 'actual_off_at' => 'datetime', 'cancelled_at' => 'datetime', 'pricing_snapshot' => 'array', 'zone_snapshot' => 'array', 'provider_snapshot' => 'array'];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function zone()
    {
        return $this->belongsTo(LightingZone::class, 'lighting_zone_id');
    }
}
