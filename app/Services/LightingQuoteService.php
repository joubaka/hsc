<?php

namespace App\Services;

use App\Models\Facility;
use Illuminate\Support\Carbon;

class LightingQuoteService
{
    public function withCustomWindow(array $quote, Carbon $on, Carbon $off, Carbon $bookingStart, Carbon $bookingEnd): array
    {
        abort_unless(($quote['applies'] ?? false) && ! empty($quote['zone_id']), 422, 'This booking has no editable lighting reservation.');
        $zone = \App\Models\LightingZone::findOrFail($quote['zone_id']);
        abort_unless($on->lt($off), 422, 'The light-off time must be after the light-on time.');
        abort_unless($on->lte($bookingStart) && $off->gte($bookingEnd), 422, 'Lighting must cover the full court booking time.');
        $minutes = (int) $on->diffInMinutes($off);
        abort_unless($minutes <= $zone->maximum_continuous_on_minutes, 422, "Lighting may run for at most {$zone->maximum_continuous_on_minutes} minutes continuously.");
        $minimum = (int) ($quote['minimum_minutes'] ?? $zone->minimum_billable_minutes);
        $increment = max(1, (int) ($quote['increment_minutes'] ?? $zone->billing_increment_minutes));
        $billable = max($minimum, (int) (ceil($minutes / $increment) * $increment));
        $rate = (int) ($quote['unit_amount'] ?? 0);
        $amount = match ($quote['pricing_method'] ?? 'fixed') {
            'hourly' => intdiv($rate * $billable, 60), 'per_minute' => $rate * $billable, default => $rate,
        };

        return [...$quote, 'scheduled_on_at' => $on->toIso8601String(), 'scheduled_off_at' => $off->toIso8601String(),
            'pre_start_minutes' => $on->diffInMinutes($bookingStart, false), 'post_end_minutes' => $bookingEnd->diffInMinutes($off, false),
            'billable_minutes' => $billable, 'amount' => $amount, 'payment_requirement' => $amount > 0 ? 'paid' : 'not_required'];
    }

    public function quote(Facility $facility, Carbon $start, Carbon $end, string $profile, bool $requested = false, bool $adminOverride = false): array
    {
        if (! $facility->lighting_available) {
            return ['available' => false, 'applies' => false, 'required' => false, 'reason' => 'This court does not have bookable lighting.', 'amount' => 0];
        }
        $zone = $facility->lightingZones()->with(['policies', 'tariffs', 'channel.device'])->where('active', true)->first();
        if (! $zone) {
            return ['available' => false, 'applies' => false, 'required' => false, 'reason' => 'Lighting has not been configured for this court.', 'amount' => 0];
        }
        $policy = $zone->policies->where('active', true)->sortByDesc('priority')->first(fn ($p) => ! $p->profile || $p->profile === $profile);
        $strategy = $policy?->strategy ?? 'manual';
        $inBand = $policy && $strategy === 'fixed_band' && $policy->starts_at && $policy->ends_at && $start->format('H:i:s') >= $policy->starts_at && $start->format('H:i:s') < $policy->ends_at;
        $required = $strategy === 'always_required' || $inBand;
        $prohibited = $strategy === 'never_available';
        if ($prohibited && ! $adminOverride) {
            return ['available' => false, 'applies' => false, 'required' => false, 'reason' => 'Lighting is not available for this court.', 'amount' => 0, 'zone_id' => $zone->id];
        }
        $applies = $required || $requested || $adminOverride;
        $reason = $required ? ($strategy === 'fixed_band' ? "Lighting is required for bookings starting from {$policy->starts_at}." : 'Lighting is required for this court.') : ($applies ? 'Lighting selected.' : 'Lighting is optional for this booking.');
        if (! $applies) {
            return ['available' => true, 'applies' => false, 'required' => false, 'reason' => $reason, 'amount' => 0, 'zone_id' => $zone->id];
        }
        $tariff = $zone->tariffs->where('active', true)->filter(fn ($t) => (! $t->profile || $t->profile === $profile) && (! $t->starts_at || $start->format('H:i:s') >= $t->starts_at) && (! $t->ends_at || $start->format('H:i:s') < $t->ends_at))->sortByDesc('priority')->first();
        $minutes = $start->diffInMinutes($end);
        $minimum = $tariff?->minimum_minutes ?? $zone->minimum_billable_minutes;
        $increment = max(1, $tariff?->increment_minutes ?? $zone->billing_increment_minutes);
        $billable = max($minimum, (int) (ceil($minutes / $increment) * $increment));
        $rate = (int) ($tariff?->amount_cents ?? 0);
        $amount = match ($tariff?->pricing_method ?? 'fixed') {
            'hourly' => intdiv($rate * $billable, 60),'per_minute' => $rate * $billable,default => $rate
        };

        return ['available' => true, 'applies' => true, 'required' => $required, 'reason' => $reason, 'zone_id' => $zone->id, 'zone' => ['id' => $zone->id, 'code' => $zone->code, 'name' => $zone->name],
            'scheduled_on_at' => $start->copy()->subMinutes($zone->pre_start_minutes)->toIso8601String(), 'scheduled_off_at' => $end->copy()->addMinutes($zone->post_end_minutes)->toIso8601String(),
            'pre_start_minutes' => $zone->pre_start_minutes, 'post_end_minutes' => $zone->post_end_minutes, 'minimum_minutes' => $minimum, 'increment_minutes' => $increment, 'billable_minutes' => $billable, 'amount' => $amount, 'currency' => 'ZAR',
            'tariff_id' => $tariff?->id, 'tariff_version' => $tariff?->version, 'tariff_name' => $tariff?->name, 'unit_amount' => $rate, 'pricing_method' => $tariff?->pricing_method ?? 'fixed',
            'financial_owner_type' => $tariff?->financial_owner_type ?? $zone->financial_owner_type, 'provider_mode' => $zone->provider_mode,
            'automation_enabled' => (bool) $zone->automatic_control_enabled, 'payment_requirement' => $amount > 0 ? 'paid' : 'not_required'];
    }
}
