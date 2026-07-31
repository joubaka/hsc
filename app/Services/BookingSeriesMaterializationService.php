<?php

namespace App\Services;

use App\Models\BookingSeries;
use App\Models\BookingSeriesOccurrence;
use App\Models\Facility;
use App\Models\Member;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class BookingSeriesMaterializationService
{
    public function __construct(
        private BookingSeriesPreviewService $previews,
        private BookingService $bookings,
        private BookingSeriesBillingService $billing,
    ) {}

    public function materialize(User $actor, BookingSeries $series, array $selected = [], bool $partial = false): array
    {
        abort_unless(app(BookingAccessService::class)->canManage($actor, $series->facility), 403);
        $preview = $this->previews->preview($actor, $series);
        $chosen = collect($preview['occurrences'])->filter(fn ($row) => $selected === [] || in_array($row['occurrence_number'], $selected, true));
        if (! $partial && $chosen->contains(fn ($row) => ! $row['eligible'])) {
            return ['mode' => 'all_or_nothing', 'created' => 0, 'outcomes' => $chosen->map(fn ($row) => [...$row, 'outcome' => $row['eligible'] ? 'not_created' : $row['status']])->values()];
        }
        $runner = function () use ($actor, $series, $chosen, $partial) {
            $outcomes = [];
            foreach ($chosen as $row) {
                $existing = BookingSeriesOccurrence::where(['booking_series_id' => $series->id, 'series_version' => $series->version, 'occurrence_number' => $row['occurrence_number']])->first();
                if ($existing?->booking_id) {
                    $outcomes[] = ['occurrence_number' => $row['occurrence_number'], 'outcome' => 'already_created', 'booking_id' => $existing->booking_id];

                    continue;
                }
                if (! $row['eligible']) {
                    BookingSeriesOccurrence::updateOrCreate(
                        ['booking_series_id' => $series->id, 'series_version' => $series->version, 'occurrence_number' => $row['occurrence_number']],
                        ['scheduled_date' => $row['local_date'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'status' => $row['status'], 'preview_snapshot' => $row, 'outcome_message' => $row['failures'][0]['member_message'] ?? null],
                    );
                    $outcomes[] = ['occurrence_number' => $row['occurrence_number'], 'outcome' => $row['status']];

                    continue;
                }
                try {
                    $holdKey = Uuid::uuid5(Uuid::NAMESPACE_URL, "hsc-series:{$series->id}:v{$series->version}:occurrence:{$row['occurrence_number']}:hold")->toString();
                    $confirmKey = Uuid::uuid5(Uuid::NAMESPACE_URL, "hsc-series:{$series->id}:v{$series->version}:occurrence:{$row['occurrence_number']}:confirm")->toString();
                    $hold = $this->bookings->createHold($actor, Facility::findOrFail($series->facility_id), Member::findOrFail($series->member_id), [
                        'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'guest_count' => $series->guest_count, 'lighting_requested' => $series->lighting_requested, 'idempotency_key' => $holdKey,
                    ]);
                    $booking = $this->bookings->confirm($actor, $hold, $confirmKey, $series->participant_template ?? [], $series->billing_strategy);
                    $occurrence = BookingSeriesOccurrence::updateOrCreate(
                        ['booking_series_id' => $series->id, 'series_version' => $series->version, 'occurrence_number' => $row['occurrence_number']],
                        ['booking_id' => $booking->id, 'scheduled_date' => $row['local_date'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'status' => 'confirmed', 'preview_snapshot' => $row],
                    );
                    $booking->lightingReservations()->update([
                        'booking_series_id' => $series->id,
                        'booking_series_occurrence_id' => $occurrence->id,
                    ]);
                    $outcomes[] = ['occurrence_number' => $row['occurrence_number'], 'outcome' => 'created', 'booking_id' => $booking->id, 'booking_reference' => $booking->booking_reference, 'occurrence_id' => $occurrence->id];
                } catch (\Throwable $e) {
                    if (! $partial) {
                        throw $e;
                    }
                    $outcomes[] = ['occurrence_number' => $row['occurrence_number'], 'outcome' => 'failed', 'message' => 'The occurrence could not be created.'];
                }
            }
            $invoice = $this->billing->createSeriesInvoice($actor, $series);
            Audit::record('booking_series.occurrences_materialized', $series, [], ['created' => collect($outcomes)->where('outcome', 'created')->count(), 'outcomes' => collect($outcomes)->pluck('outcome')->countBy()]);

            return ['mode' => $partial ? 'partial' : 'all_or_nothing', 'created' => collect($outcomes)->where('outcome', 'created')->count(), 'outcomes' => $outcomes, 'invoice' => $invoice];
        };

        return $partial ? $runner() : DB::transaction($runner, 3);
    }
}
