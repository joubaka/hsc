<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipCategory extends Model
{
    protected $guarded = [];

    protected $appends = ['code_fee', 'hsc_levy', 'total_fee'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function sportCode(): BelongsTo
    {
        return $this->belongsTo(SportCode::class);
    }

    public function getCodeFeeAttribute(): float
    {
        return $this->code_fee_cents / 100;
    }

    public function getHscLevyAttribute(): float
    {
        return $this->hsc_levy_cents / 100;
    }

    public function getTotalFeeAttribute(): float
    {
        return ($this->code_fee_cents + $this->hsc_levy_cents) / 100;
    }
}
