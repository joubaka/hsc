<?php

namespace App\Services;

use App\Models\LightingCommand;
use App\Models\LightingReservation;
use App\Models\LightingScheduleInterval;
use App\Models\LightingSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LightingSchedulePlanner
{
    public function planChannel(?int $channelId): array
    {
        if (! $channelId) {
            return [];
        }

        return DB::transaction(function () use ($channelId) {
            $gap = (int) (LightingSetting::whereNull('sport_code_id')->value('merge_window_minutes') ?? 10);
            $reservations = LightingReservation::whereHas('zone', fn ($q) => $q->where('lighting_device_channel_id', $channelId))->whereIn('status', ['scheduled', 'manual_action_required', 'on'])->where('scheduled_off_at', '>', now())->orderBy('scheduled_on_at')->lockForUpdate()->get();
            $groups = [];
            foreach ($reservations as $r) {
                $last = array_key_last($groups);
                if ($last !== null && $r->scheduled_on_at->lte($groups[$last]['end']->copy()->addMinutes($gap))) {
                    $groups[$last]['end'] = max($groups[$last]['end'], $r->scheduled_off_at);
                    $groups[$last]['ids'][] = $r->id;
                } else {
                    $groups[] = ['start' => $r->scheduled_on_at, 'end' => $r->scheduled_off_at, 'ids' => [$r->id]];
                }
            }
            $activeKeys = [];
            foreach ($groups as $g) {
                $key = hash('sha256', $channelId.'|'.$g['start']->toIso8601String().'|'.$g['end']->toIso8601String().'|'.implode(',', $g['ids']));
                $activeKeys[] = $key;
                $interval = LightingScheduleInterval::firstOrCreate(['planning_key' => $key], ['lighting_device_channel_id' => $channelId, 'starts_at' => $g['start'], 'ends_at' => $g['end'], 'status' => 'planned']);
                $interval->reservations()->sync($g['ids']);
                foreach (['switch_on' => $g['start'], 'switch_off' => $g['end']] as $type => $due) {
                    LightingCommand::firstOrCreate(['idempotency_key' => "interval:{$interval->id}:{$type}"], ['command_uuid' => (string) Str::uuid(), 'lighting_schedule_interval_id' => $interval->id, 'lighting_device_channel_id' => $channelId, 'command_type' => $type, 'due_at' => $due, 'status' => 'pending']);
                }
            }
            $obsolete = LightingScheduleInterval::where('lighting_device_channel_id', $channelId)->where('status', 'planned')->when($activeKeys, fn ($q) => $q->whereNotIn('planning_key', $activeKeys))->get();
            foreach ($obsolete as $interval) {
                $interval->update(['status' => 'superseded']);
                $interval->commands()->whereIn('status', ['pending', 'retryable_failure'])->update(['status' => 'superseded']);
            }

            return $groups;
        }, 3);
    }
}
