<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicBookingEvent extends Model
{
    protected $guarded = [];

    protected $hidden = ['request_fingerprint'];

    protected function casts(): array
    {
        return ['safe_payload' => 'array'];
    }
}
