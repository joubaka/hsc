<?php

namespace App\Services;

use App\Models\BookingPolicy;
use App\Models\BookingSetting;
use App\Models\Facility;
use Illuminate\Support\Carbon;

class BookingPolicyResolver
{
    public function resolve(Facility $facility, array $profile, Carbon $when): array
    {
        $settings = BookingSetting::firstOrCreate([]);
        $resolved = [
            'advance_days' => $settings->default_advance_days,
            'minimum_notice_minutes' => $settings->default_minimum_notice_minutes,
            'min_duration_minutes' => $settings->default_min_duration_minutes,
            'max_duration_minutes' => $settings->default_max_duration_minutes,
            'start_interval_minutes' => $facility->start_interval_minutes ?: $settings->default_start_interval_minutes,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
            'max_future_bookings' => $settings->default_max_future_bookings,
            'max_daily_bookings' => null, 'max_weekly_minutes' => null, 'max_peak_bookings' => null,
            'guest_allowed' => true, 'max_guests' => 3,
            'cancellation_hours' => 24, 'editing_hours' => 4, 'refund_percentage' => 100,
        ];
        $applied = [];
        $scopeIds = [
            'central' => null, 'sport_code' => $facility->sport_code_id, 'facility' => $facility->id,
            'facility_group' => $facility->facility_group_id,
        ];
        $rank = ['central' => 1, 'sport_code' => 2, 'facility' => 3, 'facility_group' => 4];
        $policies = BookingPolicy::where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $when->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $when->toDateString()))
            ->get()->filter(function ($policy) use ($scopeIds, $profile) {
                if (! array_key_exists($policy->scope_type, $scopeIds) || (int) $policy->scope_id !== (int) $scopeIds[$policy->scope_type]) {
                    return false;
                }
                if ($policy->profile && $policy->profile !== $profile['profile']) {
                    return false;
                }
                if ($policy->membership_category_id && (int) $policy->membership_category_id !== (int) $profile['membership_category_id']) {
                    return false;
                }

                return true;
            })->sortBy(fn ($p) => [
                $rank[$p->scope_type] + ($p->profile ? 10 : 0) + ($p->membership_category_id ? 20 : 0),
                $p->priority, $p->id,
            ]);
        foreach ($policies as $policy) {
            $resolved = array_replace($resolved, array_filter($policy->rules, fn ($value) => $value !== null));
            $applied[] = ['id' => $policy->id, 'name' => $policy->name, 'type' => $policy->policy_type, 'version' => $policy->version];
        }

        return ['values' => $resolved, 'applied' => $applied];
    }
}
