<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceCorrection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['before_snapshot' => 'array', 'after_snapshot' => 'array'];
    }

    public function attendance()
    {
        return $this->belongsTo(BookingAttendance::class, 'booking_attendance_id');
    }
}
