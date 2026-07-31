<?php

namespace App\Services;

use App\Models\AccessCommand;
use App\Models\Booking;
use App\Models\BookingAccessGrant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingAccessGrantService
{
    public function ensure(Booking $booking, array $policy): array
    {
        return DB::transaction(function () use ($booking, $policy) {
            $grants = [];
            foreach ($booking->facility->accessZones()->where('active', true)->where('allows_temporary_booking_access', true)->get() as $zone) {
                $grant = BookingAccessGrant::updateOrCreate(['booking_id' => $booking->id, 'member_id' => $booking->member_id, 'access_zone_id' => $zone->id], ['status' => 'eligible', 'valid_from' => $booking->starts_at->copy()->subMinutes($zone->default_access_lead_minutes ?: $policy['access_lead_minutes']), 'valid_until' => $booking->ends_at->copy()->addMinutes($zone->default_access_lag_minutes ?: $policy['access_lag_minutes']), 'provider_mode' => $zone->provider_mode, 'zone_snapshot' => ['id' => $zone->id, 'code' => $zone->code, 'name' => $zone->name], 'credential_type' => 'opaque_reference', 'idempotency_key' => "booking:{$booking->id}:member:{$booking->member_id}:zone:{$zone->id}"]);
                $endpoint = $zone->endpoints()->where('enabled', true)->first();
                if ($endpoint) {
                    AccessCommand::firstOrCreate(['idempotency_key' => "grant:{$grant->id}:provision"], ['command_uuid' => (string) Str::uuid(), 'booking_access_grant_id' => $grant->id, 'access_endpoint_id' => $endpoint->id, 'command_type' => 'provision', 'due_at' => $grant->valid_from, 'status' => 'pending']);
                }$grants[] = $grant;
            }

            return $grants;
        });
    }

    public function revoke(Booking $booking, string $reason = 'booking_invalid'): void
    {
        DB::transaction(function () use ($booking, $reason) {
            foreach ($booking->accessGrants()->whereNotIn('status', ['revoked', 'expired'])->get() as $grant) {
                $grant->update(['status' => 'revoked', 'revoked_at' => now(), 'failure_code' => $reason]);
                $grant->commands()->where('command_type', 'provision')->whereIn('status', ['pending', 'retryable_failure'])->update(['status' => 'superseded']);
                $endpoint = $grant->zone->endpoints()->where('enabled', true)->first();
                if ($endpoint) {
                    AccessCommand::firstOrCreate(['idempotency_key' => "grant:{$grant->id}:revoke"], ['command_uuid' => (string) Str::uuid(), 'booking_access_grant_id' => $grant->id, 'access_endpoint_id' => $endpoint->id, 'command_type' => 'revoke', 'due_at' => now(), 'status' => 'pending']);
                }
            }
        });
    }
}
