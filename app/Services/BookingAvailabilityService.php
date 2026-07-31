<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Facility;
use Illuminate\Support\Carbon;

class BookingAvailabilityService
{
    public function publicSchedule(Carbon $from, Carbon $to, ?int $sportCodeId = null): array
    {
        return Facility::with(['venue:id,name,timezone', 'group:id,name', 'tags:id,name'])
            ->where('publicly_visible', true)->where('active', true)
            ->when($sportCodeId, fn ($q) => $q->where('sport_code_id', $sportCodeId))
            ->orderBy('display_order')->get()->map(function ($facility) use ($from, $to) {
                $blocked = Booking::where('facility_id', $facility->id)->whereIn('status', ['pending_payment', 'confirmed'])
                    ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
                    ->get(['starts_at', 'ends_at'])->map(fn ($row) => ['starts_at' => $row->starts_at, 'ends_at' => $row->ends_at, 'status' => 'unavailable']);
                $holds = BookingHold::where('facility_id', $facility->id)->where('status', 'active')->where('expires_at', '>', now())
                    ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
                    ->get(['starts_at', 'ends_at'])->map(fn ($row) => ['starts_at' => $row->starts_at, 'ends_at' => $row->ends_at, 'status' => 'temporarily_unavailable']);

                return [
                    'id' => $facility->id, 'name' => $facility->name, 'facility_type' => $facility->facility_type,
                    'venue' => $facility->venue?->only(['id', 'name', 'timezone']), 'group' => $facility->group?->only(['id', 'name']),
                    'tags' => $facility->tags->map->only(['id', 'name']), 'description' => $facility->description,
                    'lighting_available' => $facility->lighting_available, 'bookable' => $facility->bookable,
                    'blocked_slots' => $blocked->concat($holds)->sortBy('starts_at')->values(),
                ];
            })->all();
    }
}
