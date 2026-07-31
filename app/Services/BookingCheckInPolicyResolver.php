<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingCheckInSetting;
use App\Models\User;

class BookingCheckInPolicyResolver
{
    public function resolve(Booking $booking, ?User $actor = null): array
    {
        $setting = BookingCheckInSetting::where('sport_code_id', $booking->sport_code_id)->first() ?? BookingCheckInSetting::whereNull('sport_code_id')->first() ?? new BookingCheckInSetting(['enabled' => true, 'opens_minutes_before' => 30, 'closes_minutes_after' => 10, 'allow_member_self_check_in' => true, 'allow_qr' => true, 'allow_short_code' => true, 'require_all_participants' => false, 'no_show_grace_minutes' => 10, 'automatic_release_enabled' => false, 'allow_without_payment' => false, 'access_lead_minutes' => 30, 'access_lag_minutes' => 15]);
        $opens = $booking->starts_at->copy()->subMinutes($setting->opens_minutes_before);
        $closes = $booking->starts_at->copy()->addMinutes($setting->closes_minutes_after);
        $valid = in_array($booking->status, ['confirmed', 'pending_payment'], true);
        $paid = $setting->allow_without_payment || in_array($booking->payment_status, ['paid', 'covered_by_allowance', 'not_required'], true);
        $allowed = $setting->enabled && $valid && $paid;

        return ['check_in_required' => (bool) $setting->enabled, 'check_in_allowed' => $allowed, 'opens_at' => $opens, 'closes_at' => $closes, 'no_show_at' => $closes->copy()->addMinutes($setting->no_show_grace_minutes), 'require_all_participants' => (bool) $setting->require_all_participants, 'qr_permitted' => (bool) $setting->allow_qr, 'short_code_permitted' => (bool) $setting->allow_short_code, 'automatic_release_eligible' => (bool) $setting->automatic_release_enabled, 'access_provisioning_eligible' => $allowed, 'failure_reason' => ! $valid ? 'booking_invalid' : (! $paid ? 'payment_required' : null), 'access_lead_minutes' => (int) $setting->access_lead_minutes, 'access_lag_minutes' => (int) $setting->access_lag_minutes];
    }
}
