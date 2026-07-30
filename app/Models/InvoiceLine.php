<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot_metadata' => 'array'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sportCode()
    {
        return $this->belongsTo(SportCode::class);
    }

    protected static function booted(): void
    {
        $immutable = fn (InvoiceLine $line) => $line->invoice?->status === 'draft' ?: throw new \LogicException('Issued invoice lines are immutable.');
        static::updating($immutable);
        static::deleting($immutable);
    }
}
