<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalNotification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['safe_payload' => 'array', 'read_at' => 'datetime'];
    }
}
