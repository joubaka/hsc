<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LightingReservation;
use App\Models\LightingZone;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LightingReservationService
{
    public function reserve(Booking $booking, array $snapshot): LightingReservation
    {
        $zone = LightingZone::findOrFail($snapshot['zone_id']);
        $eligible = (int) $snapshot['amount'] === 0 || (int) $booking->total_amount === 0
            || in_array($booking->payment_status, ['paid', 'covered_by_allowance', 'not_required'], true);
        $reservation = LightingReservation::firstOrCreate(['booking_id' => $booking->id, 'lighting_zone_id' => $zone->id], [
            'status' => $eligible ? ($zone->provider_mode === 'manual' ? 'manual_action_required' : 'scheduled') : 'pending_payment',
            'booking_series_id' => null, 'booking_series_occurrence_id' => null, 'scheduled_on_at' => $snapshot['scheduled_on_at'], 'scheduled_off_at' => $snapshot['scheduled_off_at'],
            'billable_minutes' => $snapshot['billable_minutes'], 'amount' => $snapshot['amount'], 'currency' => $snapshot['currency'],
            'pricing_snapshot' => collect($snapshot)->only(['tariff_id', 'tariff_version', 'tariff_name', 'unit_amount', 'pricing_method', 'billable_minutes', 'amount', 'financial_owner_type'])->all(),
            'zone_snapshot' => collect($snapshot)->only(['zone', 'pre_start_minutes', 'post_end_minutes'])->all(),
            'provider_snapshot' => collect($snapshot)->only(['provider_mode', 'automation_enabled'])->all(),
            'payment_requirement' => $snapshot['payment_requirement'], 'idempotency_key' => (string) Str::uuid(),
        ]);
        if ($reservation->wasRecentlyCreated) {
            Audit::record('lighting.reservation_created', $reservation, [], ['booking_id' => $booking->id, 'zone_id' => $zone->id, 'amount' => $reservation->amount]);
        }
        if ($eligible) {
            $this->planAfterCommit($zone->lighting_device_channel_id);
        }

        return $reservation;
    }

    public function markPaid(Booking $booking): void
    {
        foreach ($booking->lightingReservations()->where('status', 'pending_payment')->get() as $r) {
            $r->update(['status' => $r->zone->provider_mode === 'manual' ? 'manual_action_required' : 'scheduled']);
            $this->planAfterCommit($r->zone->lighting_device_channel_id);
        }
    }

    public function markUnpaid(Booking $booking): void
    {
        foreach ($booking->lightingReservations()->whereIn('status', ['scheduled', 'manual_action_required'])->get() as $reservation) {
            $reservation->update(['status' => 'pending_payment']);
            $this->planAfterCommit($reservation->zone->lighting_device_channel_id);
        }
    }

    public function cancel(Booking $booking): void
    {
        foreach ($booking->lightingReservations()->whereNotIn('status', ['cancelled', 'off'])->get() as $r) {
            $channel = $r->zone->lighting_device_channel_id;
            $r->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->planAfterCommit($channel);
            Audit::record('lighting.reservation_cancelled', $r, [], ['booking_id' => $booking->id]);
        }
    }

    public function reschedule(Booking $booking, array $snapshot): void
    {
        $reservation = $booking->lightingReservations()->first();
        if (! $reservation || ! ($snapshot['applies'] ?? false)) {
            $this->cancel($booking);

            return;
        }

        $oldChannel = $reservation->zone->lighting_device_channel_id;
        $zone = LightingZone::findOrFail($snapshot['zone_id']);
        $reservation->update([
            'lighting_zone_id' => $zone->id,
            'scheduled_on_at' => $snapshot['scheduled_on_at'],
            'scheduled_off_at' => $snapshot['scheduled_off_at'],
            'billable_minutes' => $snapshot['billable_minutes'],
            'amount' => $snapshot['amount'],
            'pricing_snapshot' => collect($snapshot)->only(['tariff_id', 'tariff_version', 'tariff_name', 'unit_amount', 'pricing_method', 'billable_minutes', 'amount', 'financial_owner_type'])->all(),
            'zone_snapshot' => collect($snapshot)->only(['zone', 'pre_start_minutes', 'post_end_minutes'])->all(),
        ]);
        $this->planAfterCommit($oldChannel);
        $this->planAfterCommit($zone->lighting_device_channel_id);
    }

    private function planAfterCommit(int $channelId): void
    {
        DB::afterCommit(fn () => app(LightingSchedulePlanner::class)->planChannel($channelId));
    }
}
