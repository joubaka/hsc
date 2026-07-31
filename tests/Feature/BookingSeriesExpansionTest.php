<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\User;
use App\Services\BookingFinancialCompensationService;
use App\Services\BookingRecurrenceExpansionService;
use App\Services\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingSeriesExpansionTest extends TestCase
{
    use RefreshDatabase;

    private BookingRecurrenceExpansionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BookingRecurrenceExpansionService::class);
    }

    public function test_weekly_multiple_weekday_and_fortnightly_expansion_is_deterministic(): void
    {
        $definition = $this->definition([
            'starts_on' => '2026-08-03',
            'recurrence_frequency' => 'weekly',
            'recurrence_interval' => 2,
            'days_of_week' => [1, 3],
            'occurrence_limit' => 6,
        ]);

        $first = $this->service->expand($definition);
        $second = $this->service->expand($definition);

        $this->assertSame(
            ['2026-08-03', '2026-08-05', '2026-08-17', '2026-08-19', '2026-08-31', '2026-09-02'],
            array_column($first, 'scheduled_date'),
        );
        $this->assertEquals($first, $second);
    }

    public function test_daily_series_honours_end_date_and_count_limit(): void
    {
        $byDate = $this->service->expand($this->definition([
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-05',
            'recurrence_frequency' => 'daily',
            'occurrence_limit' => null,
        ]));
        $byCount = $this->service->expand($this->definition([
            'recurrence_frequency' => 'daily',
            'occurrence_limit' => 3,
        ]));

        $this->assertCount(5, $byDate);
        $this->assertCount(3, $byCount);
    }

    public function test_monthly_fixed_and_ordinal_patterns_expand(): void
    {
        $fixed = $this->service->expand($this->definition([
            'starts_on' => '2026-01-01',
            'recurrence_frequency' => 'monthly_fixed',
            'monthly_pattern' => ['day' => 15],
            'occurrence_limit' => 3,
        ]));
        $ordinal = $this->service->expand($this->definition([
            'starts_on' => '2026-01-01',
            'recurrence_frequency' => 'monthly_ordinal',
            'monthly_pattern' => ['ordinal' => 1, 'weekday' => 7],
            'occurrence_limit' => 3,
        ]));

        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], array_column($fixed, 'scheduled_date'));
        $this->assertSame(['2026-01-04', '2026-02-01', '2026-03-01'], array_column($ordinal, 'scheduled_date'));
    }

    public function test_infinite_oversized_and_nonexistent_local_time_are_rejected(): void
    {
        foreach ([
            $this->definition(['ends_on' => null, 'occurrence_limit' => null]),
            $this->definition(['occurrence_limit' => 105]),
            $this->definition([
                'starts_on' => '2026-03-08',
                'timezone' => 'America/New_York',
                'start_time' => '02:30:00',
                'recurrence_frequency' => 'daily',
                'occurrence_limit' => 1,
            ]),
        ] as $definition) {
            try {
                $this->service->expand($definition);
                $this->fail('Invalid recurrence was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_series_preview_submission_and_approval_materialize_normal_bookings_idempotently(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@hsc.local')->firstOrFail();
        $admin->update(['api_token_hash' => hash('sha256', 'series-admin')]);
        $member = Member::where('member_number', 'HSC-00001')->firstOrFail();
        $facility = Facility::where('code', 'TEN-1')->firstOrFail();
        $facility->openingHours()->update(['opens_at' => '00:00:00', 'closes_at' => '23:59:59']);
        $payload = [
            'facility_id' => $facility->id, 'sport_code_id' => $facility->sport_code_id, 'member_id' => $member->id,
            'series_type' => 'coach_block', 'starts_on' => now()->addDays(3)->toDateString(), 'start_time' => '10:00',
            'duration_minutes' => 60, 'timezone' => 'Africa/Johannesburg', 'recurrence_frequency' => 'daily',
            'recurrence_interval' => 1, 'occurrence_limit' => 2, 'guest_count' => 0,
            'billing_strategy' => 'one_invoice_for_series', 'idempotency_key' => (string) Str::uuid(),
        ];

        $series = $this->withToken('series-admin')->postJson('/api/booking-series', $payload)->assertCreated()->json();
        $this->withToken('series-admin')->getJson("/api/booking-series/{$series['id']}/preview")
            ->assertOk()->assertJsonPath('summary.requested', 2)->assertJsonPath('summary.available', 2);
        $this->withToken('series-admin')->postJson("/api/booking-series/{$series['id']}/submit")->assertOk();
        $first = $this->withToken('series-admin')->postJson("/api/booking-series/{$series['id']}/action", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('materialization.created', 2);
        $second = $this->withToken('series-admin')->postJson("/api/booking-series/{$series['id']}/action", ['action' => 'approve']);

        $this->assertDatabaseCount('booking_series_occurrences', 2);
        $this->assertSame(2, Booking::whereIn('id', BookingSeries::find($series['id'])->occurrences()->pluck('booking_id'))->count());
        $this->assertTrue(collect($first->json('materialization.outcomes'))->every(fn ($row) => $row['outcome'] === 'created'));
        $second->assertUnprocessable();
    }

    public function test_series_invoice_is_traceable_idempotent_and_one_occurrence_credit_is_isolated(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@hsc.local')->firstOrFail();
        $admin->update(['api_token_hash' => hash('sha256', 'series-finance')]);
        $member = Member::where('member_number', 'HSC-00001')->firstOrFail();
        $facility = Facility::where('code', 'TEN-1')->firstOrFail();
        $facility->openingHours()->update(['opens_at' => '00:00:00', 'closes_at' => '23:59:59']);
        $payload = [
            'facility_id' => $facility->id, 'sport_code_id' => $facility->sport_code_id, 'member_id' => $member->id,
            'series_type' => 'league_team', 'starts_on' => now()->addDays(4)->toDateString(), 'start_time' => '09:00',
            'duration_minutes' => 60, 'timezone' => 'Africa/Johannesburg', 'recurrence_frequency' => 'daily',
            'recurrence_interval' => 1, 'occurrence_limit' => 3, 'guest_count' => 0,
            'billing_strategy' => 'one_invoice_for_series', 'idempotency_key' => (string) Str::uuid(),
        ];
        $series = $this->withToken('series-finance')->postJson('/api/booking-series', $payload)->assertCreated()->json();
        $this->withToken('series-finance')->postJson("/api/booking-series/{$series['id']}/submit")->assertOk();
        $approval = $this->withToken('series-finance')->postJson("/api/booking-series/{$series['id']}/action", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('materialization.created', 3);

        $invoice = Invoice::findOrFail($approval->json('materialization.invoice.id'));
        $originalTotal = $invoice->total_amount;
        $this->assertCount(3, $invoice->lines);
        $this->assertSame(3, $invoice->lines->pluck('source_id')->unique()->count());
        $this->assertSame($originalTotal, (int) $invoice->lines->sum('total_amount'));
        $payment = app(PaymentService::class)->record($admin, [
            'member_id' => $member->id, 'payment_reference' => 'PAY-SERIES-TEST', 'payer_name' => 'Series test',
            'received_on' => now()->toDateString(), 'payment_method' => 'eft', 'source' => 'manual',
            'currency' => 'ZAR', 'amount' => $originalTotal,
        ]);
        app(PaymentService::class)->confirm($admin, $payment);
        app(PaymentService::class)->allocate($admin, $payment, $invoice, $originalTotal, 'series-payment-allocation');
        $occurrences = BookingSeries::findOrFail($series['id'])->occurrences()->with('booking')->orderBy('occurrence_number')->get();
        $originalStart = $occurrences[0]->booking->starts_at->copy();
        $replacementStart = $originalStart->copy()->addHours(4);
        $amendmentResponse = $this->withToken('series-finance')->postJson("/api/booking-series/occurrences/{$occurrences[0]->id}/amend", [
            'starts_at' => $replacementStart->toIso8601String(), 'duration_minutes' => 60,
            'series_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Move this session only',
        ]);
        $amendmentResponse->assertOk()->assertJsonPath('id', $occurrences[0]->id);
        $movedStart = $occurrences[0]->booking->fresh()->starts_at;
        $this->assertTrue($movedStart->equalTo($replacementStart));
        $this->withToken('series-finance')->postJson("/api/booking-series/occurrences/{$occurrences[0]->id}/amend", [
            'starts_at' => $occurrences[2]->booking->starts_at->toIso8601String(), 'duration_minutes' => 60,
            'series_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Conflicting move',
        ])->assertUnprocessable();
        $this->assertTrue($occurrences[0]->booking->fresh()->starts_at->equalTo($movedStart));
        $this->assertDatabaseCount('booking_series_mutations', 1);
        $cancelledBooking = $occurrences[1]->booking;
        $compensation = app(BookingFinancialCompensationService::class)->cancel($admin, $cancelledBooking, 'Series occurrence cancellation', (string) Str::uuid());
        $repeat = app(BookingFinancialCompensationService::class)->cancel($admin, $cancelledBooking->fresh(), 'Repeated cancellation', (string) Str::uuid());

        $this->assertSame($cancelledBooking->total_amount, $compensation->eligible_amount);
        $this->assertSame($compensation->id, $repeat->id);
        $this->assertSame($originalTotal, $invoice->fresh()->total_amount);
        $this->assertSame('cancelled', $cancelledBooking->fresh()->status);
        $this->assertSame('pending_payment', $occurrences[0]->booking->fresh()->status);
        $this->assertSame('pending_payment', $occurrences[2]->booking->fresh()->status);
        $this->assertDatabaseCount('credit_notes', 1);
    }

    public function test_scoped_cancellation_move_revision_and_operational_recheck_preserve_history(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@hsc.local')->firstOrFail();
        $admin->update(['api_token_hash' => hash('sha256', 'series-mutations')]);
        $member = Member::where('member_number', 'HSC-00001')->firstOrFail();
        $origin = Facility::where('code', 'TEN-1')->firstOrFail();
        $destination = Facility::where('code', 'TEN-2')->firstOrFail();
        Booking::where('booking_reference', 'BKG-SEED-CONFIRMED')->update(['status' => 'completed']);
        $origin->openingHours()->update(['opens_at' => '00:00:00', 'closes_at' => '23:59:59']);
        $destination->openingHours()->update(['opens_at' => '00:00:00', 'closes_at' => '23:59:59']);
        $payload = [
            'facility_id' => $origin->id, 'sport_code_id' => $origin->sport_code_id, 'member_id' => $member->id,
            'series_type' => 'coach_block', 'starts_on' => now()->addDays(3)->toDateString(), 'start_time' => '08:00',
            'duration_minutes' => 60, 'timezone' => 'Africa/Johannesburg', 'recurrence_frequency' => 'daily',
            'recurrence_interval' => 1, 'occurrence_limit' => 4, 'guest_count' => 0,
            'billing_strategy' => 'one_invoice_for_series', 'idempotency_key' => (string) Str::uuid(),
        ];
        $seriesId = $this->withToken('series-mutations')->postJson('/api/booking-series', $payload)->assertCreated()->json('id');
        $this->withToken('series-mutations')->postJson("/api/booking-series/{$seriesId}/submit")->assertOk();
        $this->withToken('series-mutations')->postJson("/api/booking-series/{$seriesId}/action", ['action' => 'approve'])->assertOk();
        $series = BookingSeries::with('occurrences.booking')->findOrFail($seriesId);
        $occurrences = $series->occurrences->sortBy('occurrence_number')->values();
        $seriesInvoice = $occurrences[0]->booking->invoice;
        $invoiceTotal = $seriesInvoice->total_amount;

        $moveKey = (string) Str::uuid();
        $this->withToken('series-mutations')->postJson("/api/booking-series/{$seriesId}/move", [
            'facility_id' => $destination->id, 'scope' => 'selected', 'occurrence_ids' => [$occurrences[0]->id],
            'reason' => 'Court maintenance', 'idempotency_key' => $moveKey, 'series_version' => 1,
        ])->assertOk()->assertJsonPath('moved', 1);
        $this->assertSame($destination->id, $occurrences[0]->booking->fresh()->facility_id);
        $this->assertSame(1, \App\Models\BookingSeriesMutation::where('idempotency_key', $moveKey)->count());

        $cancelKey = (string) Str::uuid();
        $cancel = $this->withToken('series-mutations')->postJson("/api/booking-series/{$seriesId}/cancel", [
            'scope' => 'occurrence', 'effective_occurrence_id' => $occurrences[1]->id,
            'reason' => 'Member unavailable', 'idempotency_key' => $cancelKey, 'series_version' => 1,
        ])->assertOk()->assertJsonPath('summary.cancelled', 1);
        $repeat = $this->withToken('series-mutations')->postJson("/api/booking-series/{$seriesId}/cancel", [
            'scope' => 'occurrence', 'effective_occurrence_id' => $occurrences[1]->id,
            'reason' => 'Member unavailable', 'idempotency_key' => $cancelKey, 'series_version' => 1,
        ])->assertOk();
        $this->assertSame($cancel->json(), $repeat->json());
        $this->assertSame('cancelled', $occurrences[1]->booking->fresh()->status);
        $this->assertSame('confirmed', $occurrences[2]->fresh()->status);
        $this->assertSame($invoiceTotal - $occurrences[1]->booking->total_amount, $seriesInvoice->fresh()->outstanding_amount);
        $this->assertSame($occurrences[1]->booking->total_amount, $seriesInvoice->fresh()->credited_amount);
        $this->assertDatabaseHas('credit_note_lines', ['invoice_line_id' => $seriesInvoice->lines()->where('source_id', $occurrences[1]->booking_id)->value('id')]);

        $revisionKey = (string) Str::uuid();
        $revision = $this->withToken('series-mutations')->postJson("/api/booking-series/{$seriesId}/revise-future", [
            'effective_occurrence_id' => $occurrences[2]->id,
            'changes' => ['start_time' => '11:00', 'occurrence_limit' => 1],
            'selected_occurrence_ids' => [$occurrences[2]->id],
            'reason' => 'Permanent time change', 'idempotency_key' => $revisionKey, 'series_version' => 1,
        ])->assertOk()->assertJsonPath('mode', 'selected_partial')->assertJsonPath('superseded', 1);
        $successor = BookingSeries::findOrFail($revision->json('successor_id'));
        $this->assertSame($seriesId, $successor->predecessor_series_id);
        $this->assertSame($successor->id, BookingSeries::findOrFail($seriesId)->successor_series_id);
        $this->assertSame('active', BookingSeries::findOrFail($seriesId)->status);
        $this->assertSame(2, $successor->version);
        $this->assertCount(1, $successor->occurrences);
        $this->assertSame('superseded', $occurrences[2]->fresh()->status);
        $this->assertSame('confirmed', $occurrences[3]->fresh()->status);

        $successorOccurrence = $successor->occurrences()->firstOrFail();
        $successor->facility()->update(['active' => false]);
        $this->artisan('bookings:recheck-series-conflicts')->assertExitCode(0);
        $this->assertSame('facility_unavailable', $successorOccurrence->fresh()->operational_conflict_reason);
        $this->artisan('bookings:recheck-series-conflicts')->assertExitCode(0);
        $this->assertDatabaseCount('audit_logs', \App\Models\AuditLog::count());
        $successor->facility()->update(['active' => true]);
        $this->artisan('bookings:recheck-series-conflicts')->assertExitCode(0);
        $this->assertNull($successorOccurrence->fresh()->operational_conflict_at);
    }

    private function definition(array $overrides = []): array
    {
        return array_replace([
            'starts_on' => '2026-08-01',
            'ends_on' => null,
            'start_time' => '18:00:00',
            'duration_minutes' => 60,
            'timezone' => 'Africa/Johannesburg',
            'recurrence_frequency' => 'weekly',
            'recurrence_interval' => 1,
            'days_of_week' => [6],
            'monthly_pattern' => null,
            'occurrence_limit' => 4,
        ], $overrides);
    }
}
