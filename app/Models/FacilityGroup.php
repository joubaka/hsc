<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityGroup extends Model
{
    protected $guarded = [];

    public function facilities()
    {
        return $this->hasMany(Facility::class);
    }
}
