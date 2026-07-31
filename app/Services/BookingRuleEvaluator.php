<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Facility;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Carbon;

class BookingRuleEvaluator
{
    public function __construct(
        private BookingProfileResolver $profiles,
        private BookingPolicyResolver $policies,
        private BookingPriceCalculator $prices,
        private LightingQuoteService $lighting,
    ) {}

    public function evaluate(User $user, ?Member $member, Facility $facility, Carbon $start, Carbon $end, int $guestCount = 0, ?int $ignoreHoldId = null, int|array|null $ignoreBookingId = null, bool $lightingRequested = false): array
    {
        $facility->loadMissing(['venue', 'sportCode', 'openingHours', 'group']);
        $profile = $this->profiles->resolve($user, $member, $facility, $start);
        $policy = $this->policies->resolve($facility, $profile, $start);
        $rules = $policy['values'];
        $failures = $profile['failures'];
        $checks = [];
        $add = function (string $code, bool $pass, string $memberMessage, array $detail = []) use (&$failures, &$checks) {
            $row = ['code' => $code, 'passed' => $pass, 'member_message' => $memberMessage, ...$detail];
            $checks[] = $row;
            if (! $pass) {
                $failures[] = [...$row, 'administrator_message' => $detail['administrator_message'] ?? $memberMessage, 'override_possible' => $detail['override_possible'] ?? false];
            }
        };
        $minutes = $start->diffInMinutes($end);
        $add('facility_active', $facility->active && $facility->bookable, 'This court is not currently bookable.');
        $opening = $facility->openingHours->first(fn ($row) => $row->active && $row->day_of_week === $start->dayOfWeek
            && (! $row->effective_from || $row->effective_from <= $start)
            && (! $row->effective_until || $row->effective_until >= $start)
            && $start->format('H:i:s') >= $row->opens_at && $end->format('H:i:s') <= $row->closes_at);
        $add('opening_hours', (bool) $opening, 'The selected time is outside opening hours.');
        $closure = $facility->closures()->where('active', true)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
        $add('facility_closure', ! $closure, 'The court is closed during this time.');
        $daysAhead = now()->startOfDay()->diffInDays($start->copy()->startOfDay(), false);
        $add('advance_window', $daysAhead >= 0 && $daysAhead <= $rules['advance_days'], "Bookings may be made up to {$rules['advance_days']} days ahead.", ['limit' => $rules['advance_days'], 'actual' => $daysAhead]);
        $notice = now()->diffInMinutes($start, false);
        $add('minimum_notice', $notice >= $rules['minimum_notice_minutes'], "Bookings require {$rules['minimum_notice_minutes']} minutes notice.", ['limit' => $rules['minimum_notice_minutes'], 'actual' => $notice]);
        $add('start_interval', ((int) $start->format('i')) % $rules['start_interval_minutes'] === 0, "Bookings must start on a {$rules['start_interval_minutes']}-minute interval.");
        $add('duration', $minutes >= $rules['min_duration_minutes'] && $minutes <= $rules['max_duration_minutes'], "Duration must be between {$rules['min_duration_minutes']} and {$rules['max_duration_minutes']} minutes.", ['actual' => $minutes]);
        $bufferedStart = $start->copy()->subMinutes($rules['buffer_before_minutes']);
        $bufferedEnd = $end->copy()->addMinutes($rules['buffer_after_minutes']);
        $bookingConflict = Booking::where('facility_id', $facility->id)->whereIn('status', ['pending_payment', 'confirmed'])
            ->when($ignoreBookingId, fn ($query) => is_array($ignoreBookingId) ? $query->whereNotIn('id', $ignoreBookingId) : $query->whereKeyNot($ignoreBookingId))
            ->where('starts_at', '<', $bufferedEnd)->where('ends_at', '>', $bufferedStart)->exists();
        $add('booking_conflict', ! $bookingConflict, 'This time is no longer available.');
        $holdConflict = BookingHold::where('facility_id', $facility->id)->where('status', 'active')->where('expires_at', '>', now())
            ->when($ignoreHoldId, fn ($q) => $q->whereKeyNot($ignoreHoldId))
            ->where('starts_at', '<', $bufferedEnd)->where('ends_at', '>', $bufferedStart)->exists();
        $add('hold_conflict', ! $holdConflict, 'Another member is currently completing this booking.');
        if ($member) {
            $future = Booking::where('member_id', $member->id)->whereIn('status', ['pending_payment', 'confirmed'])
                ->when($ignoreBookingId, fn ($query) => is_array($ignoreBookingId) ? $query->whereNotIn('id', $ignoreBookingId) : $query->whereKeyNot($ignoreBookingId))
                ->where('starts_at', '>', now())->count();
            $add('future_quota', $future < $rules['max_future_bookings'], "You may hold up to {$rules['max_future_bookings']} future bookings.", ['limit' => $rules['max_future_bookings'], 'actual' => $future]);
            $daily = Booking::where('member_id', $member->id)->whereIn('status', ['pending_payment', 'confirmed'])
                ->when($ignoreBookingId, fn ($query) => is_array($ignoreBookingId) ? $query->whereNotIn('id', $ignoreBookingId) : $query->whereKeyNot($ignoreBookingId))
                ->whereDate('starts_at', $start)->count();
            $add('daily_quota', $rules['max_daily_bookings'] === null || $daily < $rules['max_daily_bookings'], 'Your daily booking allowance has been reached.', ['limit' => $rules['max_daily_bookings'], 'actual' => $daily]);
        }
        $add('guest_limit', $rules['guest_allowed'] && $guestCount <= $rules['max_guests'], "A maximum of {$rules['max_guests']} guests is allowed.", ['actual' => $guestCount]);
        $price = $this->prices->calculate($facility, $profile, $start, $minutes, $guestCount);
        $lighting = $this->lighting->quote($facility, $start, $end, $profile['profile'], $lightingRequested, $user->isCentralAdministrator() && $lightingRequested);
        $price['lighting_amount'] = (int) ($lighting['amount'] ?? 0);
        $price['court_amount'] = $price['total_amount'];
        $price['gross_amount'] += $price['lighting_amount'];
        $price['total_amount'] += $price['lighting_amount'];
        $cancellation = [
            'policy_ids' => collect($policy['applied'])->where('type', 'cancellation')->pluck('id')->all(),
            'version' => max(collect($policy['applied'])->where('type', 'cancellation')->pluck('version')->all() ?: [1]),
            'cancellation_deadline' => $start->copy()->subHours($rules['cancellation_hours'])->toIso8601String(),
            'editing_deadline' => $start->copy()->subHours($rules['editing_hours'])->toIso8601String(),
            'refund_percentage' => $rules['refund_percentage'], 'refund_method' => $rules['refund_method'],
            'policy_band' => $rules['policy_band'], 'late_treatment' => 'no_refund',
            'weather_treatment' => 'credit', 'club_closure_treatment' => 'full_credit', 'no_show_treatment' => 'no_refund',
        ];

        return [
            'eligible' => $failures === [], 'profile' => $profile, 'policies' => $policy['applied'],
            'checks' => $checks, 'failures' => $failures, 'price' => $price,
            'allowance' => $price['allowance'], 'cancellation' => $cancellation, 'lighting' => $lighting,
        ];
    }
}
