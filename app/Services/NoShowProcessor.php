<?php

namespace App\Services;

use App\Models\AccessAlert;
use App\Models\BookingAttendance;
use Illuminate\Support\Facades\DB;

class NoShowProcessor
{
    public function process(int $limit = 100): array
    {
        $counts = ['examined' => 0, 'marked' => 0, 'released' => 0];
        $ids = BookingAttendance::whereIn('status', ['not_open', 'open', 'missed'])->where('check_in_closes_at', '<', now())->whereNull('no_show_evaluated_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$counts) {
                $attendance = BookingAttendance::with('booking')->lockForUpdate()->find($id);
                if (! $attendance || $attendance->checked_in_at || $attendance->booking->status !== 'confirmed') {
                    return;
                }$counts['examined']++;
                $release = (bool) ($attendance->policy_snapshot['automatic_release_eligible'] ?? false);
                $attendance->update(['status' => $release ? 'released' : 'no_show', 'no_show_evaluated_at' => now(), 'no_show_marked_at' => now(), 'released_at' => $release ? now() : null, 'release_reason' => $release ? 'automatic_no_show' : null]);
                if ($release) {
                    $attendance->booking->update(['status' => 'released_no_show']);
                    app(\App\Services\LightingReservationService::class)->cancel($attendance->booking);
                    $counts['released']++;
                }app(BookingAccessGrantService::class)->revoke($attendance->booking, 'no_show');
                AccessAlert::firstOrCreate(['source_type' => $attendance::class, 'source_id' => $attendance->id, 'alert_type' => 'no_show', 'status' => 'open'], ['sport_code_id' => $attendance->booking->sport_code_id, 'severity' => 'warning', 'safe_message' => 'A booking was marked as a no-show.']);
                $counts['marked']++;
            });
        }

        return $counts;
    }
}
