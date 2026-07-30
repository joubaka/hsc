<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\MembershipPeriod;
use App\Models\SportCode;
use App\Models\SportMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipApplicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_can_create_period_and_invalid_dates_are_rejected(): void
    {
        $this->admin();
        $this->withToken('token')->postJson('/api/membership-periods', $this->periodData())->assertCreated();
        $this->withToken('token')->postJson('/api/membership-periods', [...$this->periodData(), 'ends_on' => '2026-01-01'])->assertUnprocessable();
    }

    public function test_sport_admin_cannot_manage_periods(): void
    {
        $this->sportAdmin();
        $this->withToken('token')->postJson('/api/membership-periods', $this->periodData())->assertForbidden();
    }

    public function test_submission_freezes_server_calculated_fees(): void
    {
        [$application, $category] = $this->application();
        $this->withToken('token')->postJson("/api/membership-applications/{$application->id}/action", ['action' => 'submit'])->assertOk();
        $category->update(['code_fee_cents' => 999999]);
        $this->assertDatabaseHas('membership_applications', ['id' => $application->id, 'sport_code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'total_payable_cents' => 120000]);
    }

    public function test_junior_requires_guardian_and_age_is_checked_at_period_start(): void
    {
        [$application] = $this->application('2012-06-01');
        $application->update([
            'guardian_name' => 'Adult Guardian',
            'guardian_email' => 'guardian@example.test',
            'guardian_consent' => true,
        ]);
        $this->withToken('token')->postJson("/api/membership-applications/{$application->id}/action", ['action' => 'submit'])
            ->assertUnprocessable()->assertJsonValidationErrors('guardian');
    }

    public function test_approval_is_idempotent_and_creates_historical_membership_and_audits(): void
    {
        [$application] = $this->application();
        $this->withToken('token')->postJson("/api/membership-applications/{$application->id}/action", ['action' => 'submit']);
        $application->refresh()->update(['status' => 'under_review']);
        $this->withToken('token')->postJson("/api/membership-applications/{$application->id}/action", ['action' => 'approve'])->assertOk();
        $this->withToken('token')->postJson("/api/membership-applications/{$application->id}/action", ['action' => 'approve'])->assertOk();
        $this->assertSame(1, SportMembership::count());
        $this->assertSame(1, AuditLog::where('action', 'membership_application.approved')->count());
        $this->assertDatabaseHas('sport_memberships', ['source_application_id' => $application->id, 'total_cents' => 120000]);
    }

    public function test_rejection_requires_reason_and_creates_no_membership(): void
    {
        [$application] = $this->application();
        $application->update(['status' => 'under_review']);
        $this->withToken('token')->postJson("/api/membership-applications/{$application->id}/action", ['action' => 'reject'])->assertStatus(422);
        $this->withToken('token')->postJson("/api/membership-applications/{$application->id}/action", ['action' => 'reject', 'reason' => 'Not eligible'])->assertOk();
        $this->withToken('token')->postJson("/api/membership-applications/{$application->id}/action", ['action' => 'submit'])->assertForbidden();
        $this->assertSame(0, SportMembership::count());
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $application->id,
            'action' => 'membership_application.rejected',
            'new_values->rejection_reason' => 'Not eligible',
        ]);
    }

    public function test_cross_code_application_view_and_approval_are_blocked(): void
    {
        [$application, $category, $assignedCode] = $this->application();
        $other = SportCode::create(['name' => 'Other', 'slug' => 'other']);
        $user = User::where('email', 'admin@example.com')->first();
        $user->update(['role' => 'sport_admin']);
        $user->sportCodes()->attach($other->id, ['role' => 'administrator']);
        $this->withToken('token')->getJson("/api/membership-applications/{$application->id}")->assertForbidden();
        $application->update(['status' => 'under_review']);
        $this->withToken('token')->postJson("/api/membership-applications/{$application->id}/action", ['action' => 'approve'])->assertForbidden();
    }

    private function application(?string $birthDate = '1980-01-01'): array
    {
        $this->admin();
        $code = SportCode::create(['name' => 'Tennis', 'slug' => 'tennis']);
        $category = MembershipCategory::create(['sport_code_id' => $code->id, 'name' => 'Full', 'code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'billing_period' => 'annual', 'active' => true]);
        $period = MembershipPeriod::create($this->periodData());
        $member = Member::create(['member_number' => 'HSC-TEST', 'first_name' => 'Test', 'last_name' => 'Member', 'date_of_birth' => $birthDate, 'status' => 'active']);
        $application = MembershipApplication::create(['member_id' => $member->id, 'sport_code_id' => $code->id, 'membership_category_id' => $category->id, 'membership_period_id' => $period->id, 'application_type' => 'new_membership', 'status' => 'draft']);

        return [$application, $category, $code];
    }

    private function admin(): User
    {
        return User::firstOrCreate(['email' => 'admin@example.com'], ['name' => 'Admin', 'password' => 'secret12', 'role' => 'hsc_super_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'token')]);
    }

    private function sportAdmin(): User
    {
        return User::factory()->create(['role' => 'sport_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'token')]);
    }

    private function periodData(): array
    {
        return ['name' => '2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'applications_open_on' => '2026-11-01', 'applications_close_on' => '2027-03-31', 'renewal_deadline' => '2027-01-31', 'status' => 'open'];
    }
}
