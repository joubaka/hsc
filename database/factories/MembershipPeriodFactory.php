<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class MembershipPeriodFactory extends Factory
{
    public function definition(): array
    {
        $year = fake()->numberBetween(2025, 2030);

        return [
            'name' => "$year Membership Year",
            'starts_on' => "$year-01-01",
            'ends_on' => "$year-12-31",
            'applications_open_on' => ($year - 1).'-11-01',
            'applications_close_on' => "$year-03-31",
            'renewal_deadline' => "$year-01-31",
            'status' => 'draft',
        ];
    }
}
