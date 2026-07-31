<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingRefundRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['financial_owner_breakdown' => 'array', 'processed_at' => 'datetime'];
    }

    public function compensation()
    {
        return $this->belongsTo(BookingCancellationCompensation::class, 'booking_cancellation_compensation_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
