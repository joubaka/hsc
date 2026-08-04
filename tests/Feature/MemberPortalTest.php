<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_credentials_cannot_enter_administration(): void
    {
        [$member, $user] = $this->memberUser();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'MemberTest123!'])
            ->assertUnprocessable();

        $user->update(['api_token_hash' => hash('sha256', 'member-token')]);
        $this->withToken('member-token')->getJson('/api/members')->assertForbidden();
    }

    public function test_linked_member_can_sign_in_and_only_sees_own_finance(): void
    {
        [$member, $user] = $this->memberUser();
        $other = Member::create(['member_number' => 'HSC-OTHER', 'first_name' => 'Other', 'last_name' => 'Member', 'status' => 'active']);
        Invoice::create(['member_id' => $member->id, 'currency' => 'ZAR', 'status' => 'issued', 'total_amount' => 5000, 'outstanding_amount' => 5000, 'billing_event' => 'portal-own']);
        Invoice::create(['member_id' => $other->id, 'currency' => 'ZAR', 'status' => 'issued', 'total_amount' => 9900, 'outstanding_amount' => 9900, 'billing_event' => 'portal-other']);

        $token = $this->postJson('/api/member/auth/login', ['email' => $user->email, 'password' => 'MemberTest123!'])
            ->assertOk()->assertJsonPath('member.id', $member->id)->json('token');

        $this->withToken($token)->getJson('/api/member/dashboard')
            ->assertOk()->assertJsonPath('outstanding_balance', 5000)
            ->assertJsonMissing(['billing_event' => 'portal-other']);
    }

    public function test_unlinked_account_cannot_use_member_portal(): void
    {
        $user = User::factory()->create(['role' => 'hsc_admin', 'active' => true, 'password' => 'Secret123!']);
        $this->postJson('/api/member/auth/login', ['email' => $user->email, 'password' => 'Secret123!'])->assertUnprocessable();
    }

    private function memberUser(): array
    {
        $member = Member::create(['member_number' => 'HSC-PORTAL', 'first_name' => 'Portal', 'last_name' => 'Member', 'email' => 'portal@example.com', 'status' => 'active']);
        $user = User::factory()->create(['email' => 'portal@example.com', 'password' => 'MemberTest123!', 'member_id' => $member->id, 'role' => 'member', 'active' => true]);

        return [$member, $user];
    }
}
