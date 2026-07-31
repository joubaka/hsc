<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\SportCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_access_administration_api(): void
    {
        $this->getJson('/api/members')->assertUnauthorized();
        $this->getJson('/api/membership-setup')->assertUnauthorized();
    }

    public function test_disabled_users_cannot_sign_in(): void
    {
        User::factory()->create([
            'email' => 'disabled@example.com',
            'password' => 'Secret123!',
            'active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'disabled@example.com',
            'password' => 'Secret123!',
        ])->assertUnprocessable();
    }

    public function test_protected_super_administrator_cannot_be_disabled_or_demoted(): void
    {
        $user = User::factory()->create([
            'role' => 'hsc_super_admin',
            'active' => true,
            'protected_account' => true,
        ]);

        $this->expectException(\LogicException::class);
        $user->update(['active' => false, 'role' => 'member']);
    }

    public function test_protected_super_administrator_cannot_be_deleted(): void
    {
        $user = User::factory()->create([
            'role' => 'hsc_super_admin',
            'active' => true,
            'protected_account' => true,
        ]);

        $this->expectException(\LogicException::class);
        $user->delete();
    }

    public function test_sport_administrator_sees_only_assigned_sport_code(): void
    {
        [$user, $tennis, $padel] = $this->sportAdministrator();

        $response = $this->withToken('test-token')->getJson('/api/membership-setup');

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $tennis->id);
        $this->assertNotEquals($padel->id, $response->json('0.id'));
    }

    public function test_sport_administrator_cannot_edit_another_codes_category(): void
    {
        [$user, $tennis, $padel] = $this->sportAdministrator();
        $category = MembershipCategory::create([
            'sport_code_id' => $padel->id,
            'name' => 'Full',
            'billing_period' => 'annual',
            'code_fee_cents' => 10000,
            'hsc_levy_cents' => 5000,
            'active' => true,
        ]);

        $this->withToken('test-token')->putJson("/api/membership-setup/categories/{$category->id}", [
            'sport_code_id' => $padel->id,
            'name' => 'Changed',
            'billing_period' => 'annual',
            'code_fee' => 200,
            'hsc_levy' => 50,
            'active' => true,
        ])->assertForbidden();
    }

    public function test_sport_administrator_cannot_edit_central_member_fields(): void
    {
        [$user, $tennis] = $this->sportAdministrator();
        $member = Member::create([
            'member_number' => 'HSC-99999',
            'first_name' => 'Test',
            'last_name' => 'Member',
            'status' => 'active',
        ]);
        $member->sportCodes()->attach($tennis->id, ['status' => 'active', 'role' => 'member']);

        $this->withToken('test-token')->putJson("/api/members/{$member->id}", [])->assertForbidden();
    }

    private function sportAdministrator(): array
    {
        $tennis = SportCode::create(['name' => 'Tennis', 'slug' => 'tennis', 'active' => true]);
        $padel = SportCode::create(['name' => 'Padel', 'slug' => 'padel', 'active' => true]);
        $user = User::factory()->create([
            'role' => 'sport_admin',
            'active' => true,
            'api_token_hash' => hash('sha256', 'test-token'),
        ]);
        $user->sportCodes()->attach($tennis->id, ['role' => 'administrator']);

        return [$user, $tennis, $padel];
    }
}
