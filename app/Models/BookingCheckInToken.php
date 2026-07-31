<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCheckInToken extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
