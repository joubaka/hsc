<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\MembershipPeriod;
use App\Models\SportCode;
use Illuminate\Database\Eloquent\Factories\Factory;

class MembershipApplicationFactory extends Factory
{
    protected $model = MembershipApplication::class;

    public function definition(): array
    {
        $code = SportCode::create(['name' => fake()->unique()->word(), 'slug' => fake()->unique()->slug()]);
        $category = MembershipCategory::create(['sport_code_id' => $code->id, 'name' => 'Full', 'code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'billing_period' => 'annual', 'active' => true]);

        return ['member_id' => Member::create(['member_number' => fake()->unique()->bothify('HSC-#####'), 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'date_of_birth' => '1985-01-01', 'status' => 'active'])->id, 'sport_code_id' => $code->id, 'membership_category_id' => $category->id, 'membership_period_id' => MembershipPeriod::factory()->create(['status' => 'open'])->id, 'application_type' => 'new_membership', 'status' => 'draft'];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => 'submitted', 'submitted_at' => now(), 'sport_code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'total_payable_cents' => 120000, 'billing_frequency' => 'annual']);
    }

    public function underReview(): static
    {
        return $this->submitted()->state(fn () => ['status' => 'under_review']);
    }

    public function informationRequired(): static
    {
        return $this->submitted()->state(fn () => ['status' => 'information_required', 'information_requested_at' => now()]);
    }

    public function renewal(): static
    {
        return $this->state(fn () => ['application_type' => 'renewal']);
    }

    public function rejected(): static
    {
        return $this->submitted()->state(fn () => ['status' => 'rejected', 'rejection_reason' => 'Not eligible', 'decided_at' => now()]);
    }
}
