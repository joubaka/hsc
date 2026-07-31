<?php

namespace App\Services;

use App\Models\BookingSeries;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class BookingRecurrenceExpansionService
{
    public const MAX_OCCURRENCES = 104;

    public const MAX_MONTHS = 24;

    public function expand(BookingSeries|array $source): array
    {
        $v = $source instanceof BookingSeries ? $source->toArray() : $source;
        $start = Carbon::parse($v['starts_on'], $v['timezone'])->startOfDay();
        $end = empty($v['ends_on']) ? null : Carbon::parse($v['ends_on'], $v['timezone'])->endOfDay();
        $limit = isset($v['occurrence_limit']) ? (int) $v['occurrence_limit'] : null;
        if ((! $end && ! $limit) || ($limit && ($limit < 1 || $limit > self::MAX_OCCURRENCES)) || ($end && $end->gt($start->copy()->addMonthsNoOverflow(self::MAX_MONTHS)))) {
            throw ValidationException::withMessages(['recurrence' => 'A finite series of at most 104 occurrences or 24 months is required.']);
        }
        $dates = [];
        $cursor = $start->copy();
        $frequency = $v['recurrence_frequency'];
        $interval = max(1, (int) ($v['recurrence_interval'] ?? 1));
        while ((! $end || $cursor->lte($end)) && (! $limit || count($dates) < $limit) && count($dates) < self::MAX_OCCURRENCES) {
            if ($this->matches($cursor, $start, $frequency, $interval, $v)) {
                $localTime = Carbon::parse($v['start_time'])->format('H:i:s');
                $local = Carbon::parse($cursor->toDateString().' '.$localTime, $v['timezone']);
                if ($local->format('Y-m-d H:i:s') !== $cursor->toDateString().' '.$localTime) {
                    throw ValidationException::withMessages(['start_time' => "The local time does not exist on {$cursor->toDateString()}."]);
                }
                $dates[] = ['number' => count($dates) + 1, 'scheduled_date' => $cursor->toDateString(), 'starts_at' => $local, 'ends_at' => $local->copy()->addMinutes((int) $v['duration_minutes'])];
            }
            $cursor->addDay();
        }

        return $dates;
    }

    private function matches(Carbon $date, Carbon $start, string $frequency, int $interval, array $v): bool
    {
        if ($frequency === 'daily') {
            return $start->diffInDays($date) % $interval === 0;
        }
        if ($frequency === 'weekly') {
            return intdiv($start->diffInDays($date), 7) % $interval === 0 && in_array($date->dayOfWeekIso, $v['days_of_week'] ?: [$start->dayOfWeekIso], true);
        }
        if ($frequency === 'monthly_fixed') {
            return $start->diffInMonths($date->copy()->startOfMonth()) % $interval === 0 && $date->day === (int) ($v['monthly_pattern']['day'] ?? $start->day);
        }
        if ($frequency === 'monthly_ordinal') {
            $p = $v['monthly_pattern'];
            $ordinal = (int) $p['ordinal'];
            $weekday = (int) $p['weekday'];

            return $start->diffInMonths($date->copy()->startOfMonth()) % $interval === 0 && $date->dayOfWeekIso === $weekday && (int) ceil($date->day / 7) === $ordinal;
        }
        throw ValidationException::withMessages(['recurrence_frequency' => 'Unsupported recurrence frequency.']);
    }
}
