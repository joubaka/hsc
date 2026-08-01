<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingAttendance;
use App\Models\BookingCheckInToken;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingCheckInService
{
    public function ensure(Booking $booking): BookingAttendance
    {
        $policy = app(BookingCheckInPolicyResolver::class)->resolve($booking);

        return BookingAttendance::firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'status' => now()->lt($policy['opens_at']) ? 'not_open' : 'open',
                'check_in_required' => $policy['check_in_required'],
                'check_in_opens_at' => $policy['opens_at'],
                'check_in_closes_at' => $policy['closes_at'],
                'policy_snapshot' => $policy,
            ],
        );
    }

    public function issueToken(Booking $booking, string $mode = 'booking_qr'): array
    {
        $attendance = $this->ensure($booking);

        abort_unless(
            $attendance->policy_snapshot['qr_permitted'] || $mode === 'short_code',
            422,
            'This check-in method is unavailable.',
        );

        BookingCheckInToken::where('booking_id', $booking->id)
            ->where('mode', $mode)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $raw = $mode === 'short_code'
            ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
            : Str::random(64);

        $token = BookingCheckInToken::create([
            'booking_id' => $booking->id,
            'mode' => $mode,
            'token_hash' => hash('sha256', $raw),
            'token_prefix' => substr($raw, 0, 6),
            'issued_at' => now(),
            'expires_at' => min($attendance->check_in_closes_at, now()->addMinutes(120)),
            'maximum_uses' => 1,
        ]);

        return ['token' => $raw, 'expires_at' => $token->expires_at, 'mode' => $mode];
    }

    public function checkIn(
        User $actor,
        Booking $booking,
        string $method = 'member',
        ?string $token = null,
        ?string $reason = null,
        bool $allowTemporaryAccess = true,
    ): BookingAttendance {
        return DB::transaction(function () use ($actor, $booking, $method, $token, $reason, $allowTemporaryAccess) {
            $booking = Booking::lockForUpdate()->findOrFail($booking->id);
            $admin = app(BookingAccessService::class)->canManage($actor, $booking->facility);

            abort_unless($admin || $actor->member_id === $booking->member_id, 403);

            $attendance = $this->ensure($booking);
            $policy = app(BookingCheckInPolicyResolver::class)->resolve($booking, $actor);

            abort_unless(
                $policy['check_in_allowed'],
                422,
                $policy['failure_reason'] === 'payment_required'
                    ? 'Payment must be completed before check-in.'
                    : 'This booking cannot be checked in.',
            );

            if (! $admin) {
                abort_if(now()->lt($policy['opens_at']), 422, 'Check-in has not opened.');
                abort_if(now()->gt($policy['closes_at']), 422, 'The check-in window has closed.');
            }

            if ($token !== null) {
                $record = BookingCheckInToken::where('booking_id', $booking->id)
                    ->where('token_hash', hash('sha256', $token))
                    ->whereNull('revoked_at')
                    ->first();

                abort_unless(
                    $record && $record->expires_at->isFuture() && $record->use_count < $record->maximum_uses,
                    422,
                    'The check-in token is invalid or expired.',
                );

                $record->update([
                    'used_at' => now(),
                    'use_count' => $record->use_count + 1,
                ]);
            }

            if (in_array($attendance->status, ['checked_in', 'late_checked_in'], true)) {
                return $attendance;
            }

            $attendance->update([
                'status' => now()->gt($booking->starts_at) ? 'late_checked_in' : 'checked_in',
                'checked_in_at' => now(),
                'checked_in_by_user_id' => $actor->id,
                'check_in_method' => $method,
            ]);

            foreach ($booking->participants as $participant) {
                $participant->attendance()->updateOrCreate([], [
                    'attendance_status' => $policy['require_all_participants'] ? 'checked_in' : 'expected',
                    'checked_in_at' => $policy['require_all_participants'] ? now() : null,
                    'checked_in_by_user_id' => $policy['require_all_participants'] ? $actor->id : null,
                    'method' => $policy['require_all_participants'] ? $method : null,
                ]);
            }

            if ($allowTemporaryAccess) {
                app(BookingAccessGrantService::class)->ensure($booking, $policy);
            }

            Audit::record('booking.checked_in', $attendance, [], [
                'booking_id' => $booking->id,
                'method' => $method,
                'override_reason' => $reason,
            ]);

            return $attendance->fresh();
        });
    }

    public function revokeTokens(Booking $booking): void
    {
        BookingCheckInToken::where('booking_id', $booking->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
