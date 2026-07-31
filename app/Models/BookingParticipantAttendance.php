<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingParticipantAttendance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }

    public function participant()
    {
        return $this->belongsTo(BookingParticipant::class, 'booking_participant_id');
    }
}
