<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LightingZone extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'automatic_control_enabled' => 'boolean'];
    }

    public function channel()
    {
        return $this->belongsTo(LightingDeviceChannel::class, 'lighting_device_channel_id');
    }

    public function facilities()
    {
        return $this->belongsToMany(Facility::class, 'facility_lighting_zone')->withTimestamps();
    }

    public function policies()
    {
        return $this->hasMany(LightingRequirementPolicy::class);
    }

    public function tariffs()
    {
        return $this->hasMany(LightingTariff::class);
    }

    public function reservations()
    {
        return $this->hasMany(LightingReservation::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $z) => $z->reservations()->exists() ? throw new \LogicException('Zones with reservation history cannot be deleted.') : null);
    }
}
