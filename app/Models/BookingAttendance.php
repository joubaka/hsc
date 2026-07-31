<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingAttendance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['check_in_required' => 'boolean', 'check_in_opens_at' => 'datetime', 'check_in_closes_at' => 'datetime', 'checked_in_at' => 'datetime', 'no_show_evaluated_at' => 'datetime', 'no_show_marked_at' => 'datetime', 'released_at' => 'datetime', 'policy_snapshot' => 'array'];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
