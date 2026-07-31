<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime',
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

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function hold()
    {
        return $this->belongsTo(BookingHold::class, 'booking_hold_id');
    }

    public function participants()
    {
        return $this->hasMany(BookingParticipant::class);
    }

    public function allowanceUsage()
    {
        return $this->hasOne(BookingAllowanceUsage::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Bookings cannot be hard deleted.'));
    }
}
