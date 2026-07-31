<?php

namespace App\Services;

use App\Models\BookingSeries;
use App\Models\BookingSeriesMutation;
use App\Models\BookingSeriesOccurrence;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingSeriesRevisionService
{
    public function __construct(
        private BookingSeriesPreviewService $previews,
        private BookingSeriesMaterializationService $materializer,
        private BookingFinancialCompensationService $compensation,
    ) {}

    public function revise(User $actor, BookingSeries $predecessor, BookingSeriesOccurrence $effective, array $changes, string $reason, string $idempotencyKey, array $selected = []): array
    {
        abort_unless(app(BookingAccessService::class)->canManage($actor, $predecessor->facility), 403);
        abort_unless((int) $effective->booking_series_id === (int) $predecessor->id, 422, 'The effective occurrence does not belong to this series.');
        abort_if($effective->starts_at->isPast(), 422, 'Historical occurrences cannot be revised.');
        if ($existing = BookingSeriesMutation::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing->result_snapshot;
        }

        $allEligible = $predecessor->occurrences()
            ->with('booking')
            ->where('occurrence_number', '>=', $effective->occurrence_number)
            ->whereHas('booking', fn ($query) => $query->where('starts_at', '>', now())->whereIn('status', ['confirmed', 'pending_payment']))
            ->orderBy('occurrence_number')
            ->get();
        $eligible = $allEligible;
        $partialSelection = $selected !== [];
        if ($selected !== []) {
            $eligible = $eligible->whereIn('id', $selected)->values();
            $expected = $allEligible->take($eligible->count())->pluck('id')->all();
            abort_unless($eligible->pluck('id')->all() === $expected, 422, 'Partial revisions must select a contiguous range beginning with the effective occurrence.');
        }
        abort_if($eligible->isEmpty(), 422, 'There are no eligible future occurrences to revise.');

        $definition = collect($predecessor->toArray())->only([
            'facility_id', 'sport_code_id', 'member_id', 'series_type', 'start_time', 'duration_minutes',
            'timezone', 'recurrence_frequency', 'recurrence_interval', 'days_of_week', 'monthly_pattern',
            'guest_count', 'participant_template', 'billing_strategy', 'booking_type', 'member_notes',
            'administrator_notes',
        ])->merge($changes)->merge([
            'starts_on' => Carbon::parse($changes['starts_on'] ?? $effective->scheduled_date)->toDateString(),
            'ends_on' => $changes['ends_on'] ?? $predecessor->ends_on?->toDateString(),
            'occurrence_limit' => $changes['occurrence_limit'] ?? $eligible->count(),
            'version' => $predecessor->version + 1,
        ])->all();
        $preview = $this->previews->preview($actor, $definition, $eligible->pluck('booking_id')->filter()->all());
        $replacementRows = collect($preview['occurrences'])->take($eligible->count());
        abort_if($replacementRows->count() !== $eligible->count() || $replacementRows->contains(fn ($row) => ! $row['eligible']), 422, 'The successor series has unavailable occurrences. No original bookings were changed.');

        return DB::transaction(function () use ($actor, $predecessor, $effective, $definition, $eligible, $partialSelection, $reason, $idempotencyKey, $preview) {
            $predecessor = BookingSeries::whereKey($predecessor->id)->lockForUpdate()->firstOrFail();
            if ($existing = BookingSeriesMutation::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing->result_snapshot;
            }
            abort_if($predecessor->successor_series_id, 409, 'This series already has a successor.');
            $eligible = BookingSeriesOccurrence::with('booking')->whereIn('id', $eligible->pluck('id'))->lockForUpdate()->get()->sortBy('occurrence_number')->values();
            $rootId = $predecessor->root_series_id ?: $predecessor->id;
            $successor = BookingSeries::create([
                ...$definition,
                'series_reference' => 'SER-'.strtoupper(Str::random(10)),
                'idempotency_key' => (string) Str::uuid(),
                'revision_idempotency_key' => $idempotencyKey,
                'root_series_id' => $rootId,
                'predecessor_series_id' => $predecessor->id,
                'created_by_user_id' => $actor->id,
                'status' => 'approved',
                'approval_status' => 'approved',
                'version' => $predecessor->version + 1,
                'approved_at' => now(),
                'approved_by_user_id' => $actor->id,
                'rules_snapshot' => $preview['summary'],
            ]);

            foreach ($eligible as $occurrence) {
                $this->compensation->cancel($actor, $occurrence->booking, $reason, "{$idempotencyKey}:{$occurrence->id}", 'series_revision');
            }
            $materialized = $this->materializer->materialize($actor, $successor);
            abort_if($materialized['created'] !== $eligible->count(), 422, 'The successor could not replace every eligible occurrence.');
            $newOccurrences = $successor->occurrences()->where('status', 'confirmed')->orderBy('occurrence_number')->get();
            foreach ($eligible as $index => $occurrence) {
                $occurrence->update([
                    'status' => 'superseded',
                    'exception_type' => 'series_revision',
                    'superseded_by_occurrence_id' => $newOccurrences[$index]->id,
                ]);
            }
            $predecessorChanges = ['successor_series_id' => $successor->id];
            if (! $partialSelection) {
                $predecessorChanges += [
                    'ends_on' => $effective->scheduled_date->copy()->subDay(),
                    'status' => 'superseded',
                ];
            }
            $predecessor->update($predecessorChanges);
            $result = [
                'mode' => $partialSelection ? 'selected_partial' : 'all_or_nothing',
                'predecessor_id' => $predecessor->id,
                'successor_id' => $successor->id,
                'superseded' => $eligible->count(),
                'created' => $materialized['created'],
                'occurrences' => $eligible->map(fn ($old, $index) => [
                    'old_occurrence_id' => $old->id,
                    'new_occurrence_id' => $newOccurrences[$index]->id,
                    'outcome' => 'replaced',
                ])->all(),
            ];
            BookingSeriesMutation::create([
                'booking_series_id' => $predecessor->id,
                'initiated_by_user_id' => $actor->id,
                'mutation_type' => 'successor_revision',
                'scope' => 'this_and_future',
                'idempotency_key' => $idempotencyKey,
                'series_version' => $predecessor->version,
                'request_snapshot' => ['effective_occurrence_id' => $effective->id, 'successor_definition' => $definition],
                'result_snapshot' => $result,
                'reason' => $reason,
            ]);
            Audit::record('booking_series.future_revised', $predecessor, [], [...$result, 'reason' => $reason]);

            return $result;
        }, 3);
    }
}
