<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCancellationCompensation extends Model
{
    protected $table = 'booking_cancellation_compensations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'financial_owner_breakdown' => 'array',
            'decision_snapshot' => 'array',
            'requires_finance_review' => 'boolean',
            'requested_at' => 'datetime',
        ];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creditNote()
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function refundRequest()
    {
        return $this->hasOne(BookingRefundRequest::class);
    }
}
