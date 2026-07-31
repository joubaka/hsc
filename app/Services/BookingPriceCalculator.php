<?php

namespace App\Services;

use App\Models\BookingAllowancePolicy;
use App\Models\BookingAllowanceUsage;
use App\Models\BookingPricingRule;
use App\Models\BookingSetting;
use App\Models\Facility;
use Illuminate\Support\Carbon;

class BookingPriceCalculator
{
    public function calculate(Facility $facility, array $profile, Carbon $start, int $minutes, int $guestCount): array
    {
        $rules = BookingPricingRule::where('active', true)->get()->filter(function ($rule) use ($facility, $profile, $start) {
            $scopeMatches = ($rule->scope_type === 'facility' && (int) $rule->scope_id === $facility->id)
                || ($rule->scope_type === 'facility_group' && (int) $rule->scope_id === (int) $facility->facility_group_id)
                || ($rule->scope_type === 'sport_code' && (int) $rule->scope_id === (int) $facility->sport_code_id)
                || $rule->scope_type === 'central';

            return $scopeMatches && (! $rule->profile || $rule->profile === $profile['profile'])
                && (! $rule->membership_category_id || (int) $rule->membership_category_id === (int) $profile['membership_category_id'])
                && ($rule->day_of_week === null || $rule->day_of_week === $start->dayOfWeek)
                && (! $rule->starts_at || $start->format('H:i:s') >= $rule->starts_at)
                && (! $rule->ends_at || $start->format('H:i:s') < $rule->ends_at);
        })->sortByDesc(fn ($rule) => [
            ($rule->scope_type === 'facility' ? 100 : ($rule->scope_type === 'facility_group' ? 50 : 10))
                + ($rule->profile ? 20 : 0) + ($rule->membership_category_id ? 30 : 0),
            $rule->priority, -$rule->id,
        ]);
        $rule = $rules->first();
        $rate = (int) ($rule?->amount_cents ?? BookingSetting::firstOrCreate([])->default_hourly_rate_cents);
        $base = match ($rule?->pricing_method ?? 'hourly') {
            'fixed' => $rate,
            'per_participant' => $rate * (1 + $guestCount),
            'per_guest' => $rate + ((int) $rule?->guest_fee_cents * $guestCount),
            default => intdiv($rate * $minutes, 60),
        };
        $guestFee = ($rule?->pricing_method ?? 'hourly') === 'per_guest' ? 0 : (int) ($rule?->guest_fee_cents ?? 0) * $guestCount;
        $gross = $base + $guestFee;
        $allowance = $this->allowance($facility, $profile, $start, $minutes, $gross);

        return [
            'currency' => BookingSetting::firstOrCreate([])->currency, 'pricing_rule_id' => $rule?->id,
            'pricing_rule_name' => $rule?->name ?? 'Central default', 'peak' => (bool) $rule?->peak,
            'duration_minutes' => $minutes, 'guest_count' => $guestCount, 'base_amount' => $base, 'guest_fee' => $guestFee,
            'gross_amount' => $gross, 'allowance_amount' => $allowance['value_cents'],
            'total_amount' => max(0, $gross - $allowance['value_cents']), 'allowance' => $allowance,
        ];
    }

    private function allowance(Facility $facility, array $profile, Carbon $start, int $minutes, int $gross): array
    {
        if (! $profile['member_id']) {
            return ['policy_id' => null, 'minutes' => 0, 'value_cents' => 0];
        }
        $policy = BookingAllowancePolicy::where('active', true)
            ->where(fn ($q) => $q->whereNull('sport_code_id')->orWhere('sport_code_id', $facility->sport_code_id))
            ->where(fn ($q) => $q->whereNull('facility_group_id')->orWhere('facility_group_id', $facility->facility_group_id))
            ->where(fn ($q) => $q->whereNull('profile')->orWhere('profile', $profile['profile']))
            ->first();
        if (! $policy) {
            return ['policy_id' => null, 'minutes' => 0, 'value_cents' => 0];
        }
        $periodStart = $policy->recurrence === 'month' ? $start->copy()->startOfMonth() : $start->copy()->startOfWeek();
        $periodEnd = $policy->recurrence === 'month' ? $start->copy()->endOfMonth() : $start->copy()->endOfWeek();
        $used = (int) BookingAllowanceUsage::where('booking_allowance_policy_id', $policy->id)
            ->where('member_id', $profile['member_id'])->whereNull('restored_at')
            ->whereDate('period_starts_on', $periodStart)->sum('minutes');
        $covered = min($minutes, max(0, $policy->included_minutes - $used));
        $value = $minutes > 0 ? min($gross, intdiv($gross * $covered, $minutes)) : 0;

        return ['policy_id' => $policy->id, 'minutes' => $covered, 'value_cents' => $value, 'period_starts_on' => $periodStart->toDateString(), 'period_ends_on' => $periodEnd->toDateString()];
    }
}
