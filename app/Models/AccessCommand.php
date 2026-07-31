<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessCommand extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'next_attempt_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function grant()
    {
        return $this->belongsTo(BookingAccessGrant::class, 'booking_access_grant_id');
    }

    public function endpoint()
    {
        return $this->belongsTo(AccessEndpoint::class, 'access_endpoint_id');
    }
}
