<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityCheckInToken extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'rotated_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }
}
