<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Household;
use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\MembershipPeriod;
use App\Models\SportCode;
use App\Models\SportMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThreeClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_period_and_invalid_transition_are_protected(): void
    {
        $this->admin();
        $draft = MembershipPeriod::factory()->create(['status' => 'draft']);
        $this->withToken('token')->putJson("/api/membership-periods/$draft->id", [
            'name' => 'Edited draft period',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
            'status' => 'draft',
        ])->assertOk()->assertJsonPath('name', 'Edited draft period');

        $period = MembershipPeriod::factory()->create(['status' => 'archived']);
        $this->withToken('token')->putJson("/api/membership-periods/$period->id", [...$period->toArray(), 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31'])->assertForbidden();
        $this->withToken('token')->postJson("/api/membership-periods/$draft->id/transition", ['status' => 'archived'])->assertStatus(422);
    }

    public function test_category_must_belong_to_sport_and_closed_period_rejects_draft_creation(): void
    {
        $this->admin();
        [$member,$code,$category,$period] = $this->domain();
        $other = SportCode::create(['name' => 'Padel', 'slug' => 'padel']);
        $this->withToken('token')->postJson('/api/membership-applications', $this->payload($member, $other, $category, $period))->assertJsonValidationErrors('membership_category_id');
        $period->update(['status' => 'closed']);
        $this->withToken('token')->postJson('/api/membership-applications', $this->payload($member, $code, $category, $period))->assertJsonValidationErrors('membership_period_id');
    }

    public function test_zero_discount_does_not_require_an_override_reason(): void
    {
        $this->admin();
        [$member, $code, $category, $period] = $this->domain();

        $this->withToken('token')->postJson('/api/membership-applications', [
            ...$this->payload($member, $code, $category, $period),
            'joining_fee' => 0,
            'discount' => 0,
            'override_reason' => '',
        ])->assertCreated();

        $this->withToken('token')->postJson('/api/membership-applications', [
            ...$this->payload($member, $code, $category, $period),
            'joining_fee' => 0,
            'discount' => 100,
            'override_reason' => '',
        ])->assertJsonValidationErrors('override_reason');
    }

    public function test_read_only_user_cannot_mutate_assigned_application(): void
    {
        [$member,$code,$category,$period] = $this->domain();
        $user = User::factory()->create(['role' => 'sport_viewer', 'active' => true, 'api_token_hash' => hash('sha256', 'token')]);
        $user->sportCodes()->attach($code->id, ['role' => 'viewer']);
        $application = MembershipApplication::create([...$this->payload($member, $code, $category, $period), 'joining_fee_cents' => 0, 'discount_cents' => 0, 'status' => 'draft']);
        $this->withToken('token')->postJson("/api/membership-applications/$application->id/action", ['action' => 'submit'])->assertForbidden();
    }

    public function test_linked_guardian_must_be_adult_in_same_household(): void
    {
        $this->admin();
        [$minor,$code,$category,$period] = $this->domain('2012-01-01');
        $one = Household::create(['name' => 'One']);
        $two = Household::create(['name' => 'Two']);
        $minor->update(['household_id' => $one->id]);
        $outsider = Member::create(['member_number' => 'HSC-G1', 'first_name' => 'Adult', 'last_name' => 'Other', 'date_of_birth' => '1980-01-01', 'household_id' => $two->id, 'status' => 'active']);
        $this->withToken('token')->postJson('/api/membership-applications', [...$this->payload($minor, $code, $category, $period), 'guardian_member_id' => $outsider->id, 'guardian_relationship' => 'Parent', 'guardian_consent' => true])->assertStatus(422);
    }

    public function test_renewal_dashboard_and_bulk_creation_are_rerunnable(): void
    {
        $this->admin();
        [$member,$code,$category,$target] = $this->domain();
        $previous = MembershipPeriod::factory()->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'archived']);
        SportMembership::create(['member_id' => $member->id, 'sport_code_id' => $code->id, 'membership_category_id' => $category->id, 'membership_period_id' => $previous->id, 'status' => 'active', 'role' => 'member', 'joined_or_renewed_on' => '2026-01-01', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'sport_code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'total_cents' => 120000, 'billing_frequency' => 'annual']);
        $query = "?membership_period_id=$target->id&sport_code_id=$code->id";
        $this->withToken('token')->getJson("/api/renewals/dashboard$query")->assertJsonPath('counts.not_started', 1);
        $this->withToken('token')->postJson('/api/renewals/bulk-drafts', ['membership_period_id' => $target->id, 'sport_code_id' => $code->id])->assertJsonPath('results.0.outcome', 'created');
        $this->withToken('token')->postJson('/api/renewals/bulk-drafts', ['membership_period_id' => $target->id, 'sport_code_id' => $code->id])->assertJsonPath('results.0.outcome', 'already_exists');
        $this->assertSame(1, MembershipApplication::where('application_type', 'renewal')->count());
    }

    public function test_application_audit_is_scoped_and_contains_no_access_credentials(): void
    {
        $this->admin();
        [$member,$code,$category,$period] = $this->domain();
        $application = MembershipApplication::create([...$this->payload($member, $code, $category, $period), 'joining_fee_cents' => 0, 'discount_cents' => 0, 'status' => 'draft']);
        AuditLog::create(['user_id' => 1, 'action' => 'membership_application.created', 'auditable_type' => MembershipApplication::class, 'auditable_id' => $application->id, 'new_values' => ['status' => 'draft']]);
        $this->withToken('token')->getJson("/api/membership-applications/$application->id/audit")->assertOk()->assertJsonMissing(['pin'])->assertJsonPath('0.action', 'membership_application.created');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'hsc_super_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'token')]);
    }

    private function domain(string $dob = '1980-01-01'): array
    {
        $code = SportCode::create(['name' => 'Tennis', 'slug' => 'tennis']);
        $category = MembershipCategory::create(['sport_code_id' => $code->id, 'name' => 'Full', 'code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'billing_period' => 'annual', 'active' => true]);
        $period = MembershipPeriod::factory()->create(['starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'status' => 'open']);
        $member = Member::create(['member_number' => 'HSC-M1', 'first_name' => 'Member', 'last_name' => 'One', 'date_of_birth' => $dob, 'status' => 'active']);

        return [$member, $code, $category, $period];
    }

    private function payload($member, $code, $category, $period): array
    {
        return ['member_id' => $member->id, 'sport_code_id' => $code->id, 'membership_category_id' => $category->id, 'membership_period_id' => $period->id, 'application_type' => 'new_membership'];
    }
}
