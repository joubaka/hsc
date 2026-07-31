<?php

namespace Tests\Feature;

use App\Services\BookingRecurrenceExpansionService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingSeriesExpansionTest extends TestCase
{
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
