<?php

namespace Database\Factories;

use App\Models\SportMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

class SportMembershipFactory extends Factory
{
    protected $model = SportMembership::class;

    public function definition(): array
    {
        $application = \App\Models\MembershipApplication::factory()->submitted()->create();

        return ['member_id' => $application->member_id, 'sport_code_id' => $application->sport_code_id, 'membership_category_id' => $application->membership_category_id, 'membership_period_id' => $application->membership_period_id, 'status' => 'active', 'role' => 'member', 'joined_or_renewed_on' => $application->period->starts_on, 'starts_on' => $application->period->starts_on, 'ends_on' => $application->period->ends_on, 'sport_code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'total_cents' => 120000, 'billing_frequency' => 'annual'];
    }
}
