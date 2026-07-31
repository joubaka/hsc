<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessAlert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
