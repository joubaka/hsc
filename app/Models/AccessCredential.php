<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessCredential extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'areas' => 'array',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'issued_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
