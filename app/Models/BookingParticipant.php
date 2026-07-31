<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingParticipant extends Model
{
    protected $guarded = [];

    public function attendance()
    {
        return $this->hasOne(BookingParticipantAttendance::class);
    }
}
