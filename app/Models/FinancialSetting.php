<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['generate_zero_value_invoices' => 'boolean'];
    }
}
