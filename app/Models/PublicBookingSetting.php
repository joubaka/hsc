<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicBookingSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'dry_run_checkout_enabled' => 'boolean', 'email_verification_required' => 'boolean', 'mobile_verification_required' => 'boolean', 'payment_required' => 'boolean', 'temporary_access_eligible' => 'boolean'];
    }
}
