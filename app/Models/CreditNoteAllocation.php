<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNoteAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'reversed_at' => 'datetime'];
    }
}
