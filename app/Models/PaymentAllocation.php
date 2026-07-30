<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['allocated_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
