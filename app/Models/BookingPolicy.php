<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingPolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rules' => 'array', 'active' => 'boolean', 'effective_from' => 'date', 'effective_until' => 'date'];
    }
}
