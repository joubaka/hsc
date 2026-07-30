<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_on' => 'date'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function lines()
    {
        return $this->hasMany(CreditNoteLine::class);
    }

    public function allocations()
    {
        return $this->hasMany(CreditNoteAllocation::class);
    }

    protected static function booted(): void
    {
        static::deleting(fn (CreditNote $note) => $note->status === 'draft' ?: throw new \LogicException('Issued credit notes cannot be deleted.'));
    }
}
