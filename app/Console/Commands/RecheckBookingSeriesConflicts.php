<?php

namespace App\Console\Commands;

use App\Models\BookingSeriesOccurrence;
use App\Support\Audit;
use Illuminate\Console\Command;

class RecheckBookingSeriesConflicts extends Command
{
    protected $signature = 'bookings:recheck-series-conflicts';

    protected $description = 'Flag future recurring-booking occurrences affected by operational facility changes';

    public function handle(): int
    {
        $counts = ['examined' => 0, 'newly_conflicted' => 0, 'already_flagged' => 0, 'resolved' => 0, 'failed' => 0];
        BookingSeriesOccurrence::with('booking.facility.openingHours')
            ->where('status', 'confirmed')
            ->where('starts_at', '>', now())
            ->orderBy('id')
            ->chunkById(100, function ($occurrences) use (&$counts) {
                foreach ($occurrences as $occurrence) {
                    $counts['examined']++;
                    try {
                        $booking = $occurrence->booking;
                        $facility = $booking?->facility;
                        $reason = null;
                        if (! $booking || ! $facility || ! $facility->active || ! $facility->bookable) {
                            $reason = 'facility_unavailable';
                        } elseif ($facility->closures()->where('active', true)->where('starts_at', '<', $booking->ends_at)->where('ends_at', '>', $booking->starts_at)->exists()) {
                            $reason = 'facility_closure';
                        } else {
                            $opening = $facility->openingHours->first(fn ($row) => $row->active
                                && $row->day_of_week === $booking->starts_at->dayOfWeek
                                && (! $row->effective_from || $row->effective_from <= $booking->starts_at)
                                && (! $row->effective_until || $row->effective_until >= $booking->starts_at)
                                && $booking->starts_at->format('H:i:s') >= $row->opens_at
                                && $booking->ends_at->format('H:i:s') <= $row->closes_at);
                            if (! $opening) {
                                $reason = 'outside_opening_hours';
                            }
                        }

                        if ($reason && ! $occurrence->operational_conflict_at) {
                            $occurrence->update(['operational_conflict_at' => now(), 'operational_conflict_reason' => $reason]);
                            Audit::record('booking_series.occurrence_operational_conflict', $occurrence, [], ['reason' => $reason]);
                            $counts['newly_conflicted']++;
                        } elseif ($reason) {
                            $counts['already_flagged']++;
                        } elseif ($occurrence->operational_conflict_at) {
                            $occurrence->update(['operational_conflict_at' => null, 'operational_conflict_reason' => null]);
                            Audit::record('booking_series.occurrence_operational_conflict_resolved', $occurrence);
                            $counts['resolved']++;
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                        $counts['failed']++;
                    }
                }
            });
        $this->table(array_keys($counts), [array_values($counts)]);

        return $counts['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
