<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingHold extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'expires_at' => 'datetime',
            'rule_snapshot' => 'array', 'price_snapshot' => 'array', 'allowance_snapshot' => 'array', 'cancellation_snapshot' => 'array',
        ];
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
