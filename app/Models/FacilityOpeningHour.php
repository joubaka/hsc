<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityOpeningHour extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'active' => 'boolean'];
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }
}
