<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LightingDeviceChannel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'last_state_checked_at' => 'datetime', 'manual_lock_expires_at' => 'datetime'];
    }

    public function device()
    {
        return $this->belongsTo(LightingDevice::class, 'lighting_device_id');
    }

    public function commands()
    {
        return $this->hasMany(LightingCommand::class);
    }

    public function zones()
    {
        return $this->hasMany(LightingZone::class, 'lighting_device_channel_id');
    }
}
