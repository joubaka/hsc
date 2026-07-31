<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\Facility;
use App\Models\Member;
use App\Models\User;
use App\Services\BookingRecurrenceExpansionService;
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
            'billing_strategy' => 'invoice_each_occurrence', 'idempotency_key' => (string) Str::uuid(),
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
