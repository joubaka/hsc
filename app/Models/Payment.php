<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'confirmed_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receipt()
    {
        return $this->hasOne(Receipt::class);
    }

    public function transactions()
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn (Payment $payment) => $payment->status === 'pending' ?: throw new \LogicException('Confirmed payments cannot be deleted.'));
    }
}
