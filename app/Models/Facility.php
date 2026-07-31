<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Facility extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'indoor' => 'boolean', 'lighting_available' => 'boolean', 'publicly_visible' => 'boolean',
            'bookable' => 'boolean', 'active' => 'boolean',
        ];
    }

    public function lightingZones()
    {
        return $this->belongsToMany(LightingZone::class, 'facility_lighting_zone')->withTimestamps();
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function sportCode()
    {
        return $this->belongsTo(SportCode::class);
    }

    public function group()
    {
        return $this->belongsTo(FacilityGroup::class, 'facility_group_id');
    }

    public function tags()
    {
        return $this->belongsToMany(FacilityTag::class);
    }

    public function openingHours()
    {
        return $this->hasMany(FacilityOpeningHour::class);
    }

    public function closures()
    {
        return $this->hasMany(FacilityClosure::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (Facility $facility) {
            if ($facility->bookings()->exists()) {
                throw new \LogicException('Facilities with bookings cannot be deleted.');
            }
        });
    }
}
