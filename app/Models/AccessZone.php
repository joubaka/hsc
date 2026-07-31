<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessZone extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'public_entry_zone' => 'boolean', 'requires_booking' => 'boolean', 'allows_permanent_member_access' => 'boolean', 'allows_temporary_booking_access' => 'boolean'];
    }

    public function facilities()
    {
        return $this->belongsToMany(Facility::class, 'facility_access_zone')->withTimestamps();
    }

    public function endpoints()
    {
        return $this->hasMany(AccessEndpoint::class);
    }
}
