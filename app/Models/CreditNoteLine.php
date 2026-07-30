<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNoteLine extends Model
{
    protected $guarded = [];

    public function creditNote()
    {
        return $this->belongsTo(CreditNote::class);
    }
}
