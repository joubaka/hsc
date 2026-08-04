<?php

namespace Tests\Feature;

use App\Jobs\SendCommunicationEmail;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationDelivery;
use App\Models\CommunicationSetting;
use App\Models\Member;
use App\Models\User;
use App\Services\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommunicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_announcement_targets_only_active_consented_members(): void
    {
        Queue::fake();
        CommunicationSetting::current()->update(['enabled' => true, 'announcements_enabled' => true]);
        $admin = User::factory()->create(['role' => 'hsc_super_admin', 'active' => true]);
        $consented = Member::create(['member_number' => 'MAIL-1', 'first_name' => 'Email', 'last_name' => 'Yes', 'email' => 'yes@example.com', 'status' => 'active', 'email_consent' => true]);
        Member::create(['member_number' => 'MAIL-2', 'first_name' => 'Email', 'last_name' => 'No', 'email' => 'no@example.com', 'status' => 'active', 'email_consent' => false]);
        Member::create(['member_number' => 'MAIL-3', 'first_name' => 'Inactive', 'last_name' => 'Member', 'email' => 'inactive@example.com', 'status' => 'resigned', 'email_consent' => true]);

        $campaign = app(CommunicationService::class)->announcement($admin, ['name' => 'Club update', 'subject' => 'News', 'body' => '<script>bad()</script>Hello', 'audience_type' => 'all', 'scheduled_at' => null]);

        $this->assertCount(1, $campaign->deliveries);
        $this->assertSame($consented->id, $campaign->deliveries->first()->member_id);
        $this->assertStringNotContainsString('<script>', $campaign->body_html);
        Queue::assertPushed(SendCommunicationEmail::class, 1);
    }

    public function test_member_inbox_is_scoped_and_read_receipt_is_recorded(): void
    {
        [$member, $user] = $this->memberUser('MAIL-A', 'a@example.com');
        [$other] = $this->memberUser('MAIL-B', 'b@example.com');
        $campaign = CommunicationCampaign::create(['type' => 'announcement', 'name' => 'Notice', 'subject' => 'Notice', 'body_html' => 'Body', 'audience_type' => 'all', 'status' => 'sent']);
        $own = CommunicationDelivery::create(['communication_campaign_id' => $campaign->id, 'member_id' => $member->id, 'recipient_email' => $member->email, 'subject' => 'Own message', 'body_html' => 'Own', 'status' => 'sent', 'idempotency_key' => 'own']);
        CommunicationDelivery::create(['communication_campaign_id' => $campaign->id, 'member_id' => $other->id, 'recipient_email' => $other->email, 'subject' => 'Private other message', 'body_html' => 'Other', 'status' => 'sent', 'idempotency_key' => 'other']);
        $user->update(['api_token_hash' => hash('sha256', 'member-mail')]);

        $this->withToken('member-mail')->getJson('/api/member/messages')->assertOk()->assertJsonFragment(['subject' => 'Own message'])->assertJsonMissing(['subject' => 'Private other message']);
        $this->withToken('member-mail')->postJson("/api/member/messages/{$own->id}/read")->assertOk();
        $this->assertNotNull($own->fresh()->read_at);
    }

    public function test_non_central_administrator_cannot_send_bulk_email(): void
    {
        $user = User::factory()->create(['role' => 'sport_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'sport-admin')]);
        $this->withToken('sport-admin')->postJson('/api/communications', ['name' => 'No', 'subject' => 'No', 'body' => 'No', 'audience_type' => 'all'])->assertForbidden();
    }

    public function test_in_app_settings_control_automatic_and_announcement_email(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'hsc_super_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'settings-admin')]);
        $this->memberUser('MAIL-SETTINGS', 'settings@example.com');
        CommunicationSetting::current()->update([
            'announcements_enabled' => false,
            'booking_confirmations_enabled' => false,
            'sender_name' => 'HSC Test Sender',
            'booking_reminder_hours' => 48,
        ]);

        $this->withToken('settings-admin')->postJson('/api/communications', ['name' => 'Disabled', 'subject' => 'Disabled', 'body' => 'Disabled', 'audience_type' => 'all'])->assertUnprocessable();
        $this->assertNull(app(CommunicationService::class)->bookingConfirmed(999999));
        $this->assertSame('HSC Test Sender', CommunicationSetting::current()->sender_name);
        $this->assertSame(48, CommunicationSetting::current()->booking_reminder_hours);
        Queue::assertNothingPushed();
    }

    private function memberUser(string $number, string $email): array
    {
        $member = Member::create(['member_number' => $number, 'first_name' => 'Test', 'last_name' => $number, 'email' => $email, 'status' => 'active', 'email_consent' => true]);
        return [$member, User::factory()->create(['email' => $email, 'member_id' => $member->id, 'role' => 'member', 'active' => true])];
    }
}
