<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LightingCommand extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'next_attempt_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function channel()
    {
        return $this->belongsTo(LightingDeviceChannel::class, 'lighting_device_channel_id');
    }

    public function interval()
    {
        return $this->belongsTo(LightingScheduleInterval::class, 'lighting_schedule_interval_id');
    }
}
