<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingCheckInSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'allow_member_self_check_in' => 'boolean', 'allow_qr' => 'boolean', 'allow_short_code' => 'boolean', 'require_all_participants' => 'boolean', 'automatic_no_show_enabled' => 'boolean', 'automatic_release_enabled' => 'boolean', 'allow_without_payment' => 'boolean'];
    }
}
