<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingAllowanceUsage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_starts_on' => 'date', 'period_ends_on' => 'date', 'restored_at' => 'datetime'];
    }
}
