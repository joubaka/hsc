<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicBookingCheckout extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'paid_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function hold()
    {
        return $this->belongsTo(BookingHold::class, 'booking_hold_id');
    }

    public function visitor()
    {
        return $this->belongsTo(PublicVisitor::class, 'public_visitor_id');
    }
}
