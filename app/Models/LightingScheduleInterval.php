<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LightingScheduleInterval extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function channel()
    {
        return $this->belongsTo(LightingDeviceChannel::class, 'lighting_device_channel_id');
    }

    public function reservations()
    {
        return $this->belongsToMany(LightingReservation::class, 'lighting_interval_reservation');
    }

    public function commands()
    {
        return $this->hasMany(LightingCommand::class);
    }
}
