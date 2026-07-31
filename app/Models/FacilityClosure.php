<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityClosure extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'active' => 'boolean', 'cancelled_at' => 'datetime'];
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }
}
