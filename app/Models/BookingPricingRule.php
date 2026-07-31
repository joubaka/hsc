<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingPricingRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['peak' => 'boolean', 'active' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date'];
    }
}
