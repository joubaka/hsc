<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LightingSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['automation_enabled' => 'boolean', 'dry_run' => 'boolean', 'require_paid' => 'boolean', 'allow_allowance' => 'boolean', 'allow_waived' => 'boolean', 'members_may_select' => 'boolean'];
    }
}
