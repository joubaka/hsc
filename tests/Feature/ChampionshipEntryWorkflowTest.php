<?php

namespace Tests\Feature;

use App\Models\ChampionshipEvent;
use App\Models\ClubChampionship;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipPeriod;
use App\Models\SportCode;
use App\Models\SportMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChampionshipEntryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_members_become_separate_singles_entries(): void
    {
        $admin = User::factory()->create([
            'role' => 'hsc_super_admin',
            'active' => true,
            'api_token_hash' => hash('sha256', 'championship-admin'),
        ]);
        $championship = ClubChampionship::create([
            'name' => 'Junior Championships',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-05',
            'age_cutoff_on' => '2026-09-01',
            'convenor_user_id' => $admin->id,
        ]);
        $event = ChampionshipEvent::create([
            'club_championship_id' => $championship->id,
            'name' => 'Junior Boys',
            'discipline' => 'singles',
            'gender_rule' => 'male',
            'draw_format' => 'knockout',
            'entry_fee_cents' => 0,
            'fee_basis' => 'player',
        ]);

        $members = collect(range(1, 4))->map(fn (int $number) => $this->activeMember($number));

        $this->withToken('championship-admin')
            ->postJson("/api/championship-events/{$event->id}/entries", [
                'member_ids' => $members->pluck('id')->all(),
            ])
            ->assertCreated()
            ->assertJsonCount(4, 'entries');

        $this->assertSame(4, $event->entries()->count());
        $this->assertTrue($event->entries()->withCount('members')->get()->every(
            fn ($entry) => $entry->members_count === 1
        ));

        foreach ($event->entries()->take(2)->get() as $entry) {
            $this->withToken('championship-admin')
                ->patchJson("/api/championship-entries/{$entry->id}", [
                    'status' => 'pending',
                    'payment_status' => 'paid',
                ])
                ->assertOk();
        }

        $this->withToken('championship-admin')
            ->postJson("/api/championship-events/{$event->id}/draws")
            ->assertCreated();

        $this->assertSame(1, $event->draws()->count());
        $this->assertSame(4, $event->entries()->where('status', 'accepted')->count());

        $settings = [
            'name' => 'Junior Boys Updated', 'age_category_id' => null,
            'discipline' => 'singles', 'gender_rule' => 'male', 'draw_format' => 'round_robin',
            'ranking_rules' => ['matches_won', 'sets_won', 'game_percentage', 'head_to_head'],
            'entry_fee_cents' => 5000, 'fee_basis' => 'player', 'capacity' => null, 'seed_count' => 4,
        ];
        $this->withToken('championship-admin')->patchJson("/api/championship-events/{$event->id}", $settings)->assertUnprocessable();
        $draw = $event->draws()->first();
        $this->withToken('championship-admin')->deleteJson("/api/championship-draws/{$draw->id}")->assertNoContent();
        $this->withToken('championship-admin')->patchJson("/api/championship-events/{$event->id}", $settings)->assertOk();
        $this->assertSame('round_robin', $event->fresh()->draw_format);
        $this->assertSame($settings['ranking_rules'], $event->fresh()->ranking_rules);
    }

    private function activeMember(int $number): Member
    {
        $sport = SportCode::firstOrCreate(['slug' => 'tennis'], ['name' => 'Tennis']);
        $category = MembershipCategory::firstOrCreate(
            ['sport_code_id' => $sport->id, 'name' => 'Junior'],
            ['billing_period' => 'annual', 'code_fee_cents' => 0, 'hsc_levy_cents' => 0, 'active' => true]
        );
        $period = MembershipPeriod::firstOrCreate(
            ['name' => '2026'],
            ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']
        );
        $member = Member::create([
            'member_number' => "CHAMP-{$number}",
            'first_name' => "Junior {$number}",
            'last_name' => 'Player',
            'date_of_birth' => '2010-01-01',
            'gender' => 'male',
            'status' => 'active',
        ]);
        SportMembership::create([
            'member_id' => $member->id,
            'sport_code_id' => $sport->id,
            'membership_category_id' => $category->id,
            'membership_period_id' => $period->id,
            'status' => 'active',
            'role' => 'member',
            'joined_or_renewed_on' => '2026-01-01',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'sport_code_fee_cents' => 0,
            'hsc_levy_cents' => 0,
            'total_cents' => 0,
            'billing_frequency' => 'annual',
        ]);

        return $member;
    }
}
