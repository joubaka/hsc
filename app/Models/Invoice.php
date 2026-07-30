<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'due_on' => 'date', 'voided_at' => 'datetime'];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function period()
    {
        return $this->belongsTo(MembershipPeriod::class, 'membership_period_id');
    }

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class);
    }

    public function transactions()
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn (Invoice $invoice) => $invoice->status === 'draft' ?: throw new \LogicException('Posted invoices cannot be deleted.'));
    }
}
