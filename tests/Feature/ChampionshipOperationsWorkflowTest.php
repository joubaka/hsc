<?php

namespace Tests\Feature;

use App\Models\{ChampionshipDraw, ChampionshipEntry, ChampionshipEvent, ChampionshipMatch, ClubChampionship, Facility, Member, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChampionshipOperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_match_can_be_scheduled_operated_scored_and_verified(): void
    {
        $admin=User::factory()->create(['role'=>'hsc_super_admin','active'=>true,'api_token_hash'=>hash('sha256','ops-admin')]);
        $venueId=DB::table('venues')->insertGetId(['name'=>'HSC','code'=>'HSC','created_at'=>now(),'updated_at'=>now()]);
        $facility=Facility::create(['venue_id'=>$venueId,'name'=>'Court 1','code'=>'C1','facility_type'=>'court','active'=>true]);
        $championship=ClubChampionship::create(['name'=>'Club Champs','starts_on'=>'2026-09-01','ends_on'=>'2026-09-05','age_cutoff_on'=>'2026-09-01','convenor_user_id'=>$admin->id]);
        $event=ChampionshipEvent::create(['club_championship_id'=>$championship->id,'name'=>'Open singles','discipline'=>'singles','gender_rule'=>'open','draw_format'=>'knockout','entry_fee_cents'=>0,'fee_basis'=>'player']);
        $entries=collect(range(1,4))->map(function($number)use($event){$member=Member::create(['member_number'=>'OPS-'.$number,'first_name'=>'Player','last_name'=>(string)$number,'status'=>'active']);$entry=ChampionshipEntry::create(['championship_event_id'=>$event->id,'status'=>'accepted','payment_status'=>'paid']);$entry->members()->attach($member->id,['position'=>1,'confirmation_status'=>'confirmed']);return $entry;});
        $draw=ChampionshipDraw::create(['championship_event_id'=>$event->id,'version'=>1,'format'=>'knockout']);
        $semi1=ChampionshipMatch::create(['championship_draw_id'=>$draw->id,'round_number'=>1,'round_name'=>'Semi-finals','match_number'=>1,'entry1_id'=>$entries[0]->id,'entry2_id'=>$entries[1]->id,'status'=>'ready_to_schedule']);
        $semi2=ChampionshipMatch::create(['championship_draw_id'=>$draw->id,'round_number'=>1,'round_name'=>'Semi-finals','match_number'=>2,'entry1_id'=>$entries[2]->id,'entry2_id'=>$entries[3]->id,'status'=>'ready_to_schedule']);
        $final=ChampionshipMatch::create(['championship_draw_id'=>$draw->id,'round_number'=>2,'round_name'=>'Final','match_number'=>1,'source1_match_id'=>$semi1->id,'source2_match_id'=>$semi2->id,'source1_outcome'=>'winner','source2_outcome'=>'winner','status'=>'pending_players']);

        $this->withToken('ops-admin')->postJson("/api/championship-matches/{$semi1->id}/schedule",['scheduled_at'=>'2026-09-01 09:00:00','facility_id'=>$facility->id])->assertOk()->assertJsonPath('status','scheduled');
        $this->assertDatabaseHas('bookings',['id'=>$semi1->fresh()->booking_id,'profile'=>'championship']);
        $this->withToken('ops-admin')->patchJson("/api/championship-matches/{$semi1->id}/desk",['action'=>'start'])->assertOk()->assertJsonPath('status','live');
        $this->withToken('ops-admin')->postJson("/api/championship-matches/{$semi1->id}/result",['winner_entry_id'=>$entries[0]->id,'final_score'=>'6-4, 6-3'])->assertOk()->assertJsonPath('status','completed');
        $this->assertSame($entries[0]->id,$final->fresh()->entry1_id);
        $this->withToken('ops-admin')->postJson("/api/championship-matches/{$semi1->id}/verify")->assertOk()->assertJsonPath('result_status','verified');
    }

    public function test_operations_setup_and_order_of_play_are_versioned(): void
    {
        $admin=User::factory()->create(['role'=>'hsc_super_admin','active'=>true,'api_token_hash'=>hash('sha256','ops-setup')]);
        $championship=ClubChampionship::create(['name'=>'Club Champs','starts_on'=>'2026-09-01','ends_on'=>'2026-09-05','age_cutoff_on'=>'2026-09-01','convenor_user_id'=>$admin->id]);
        $this->withToken('ops-setup')->postJson("/api/championships/{$championship->id}/operations/sessions",['name'=>'Day 1','play_date'=>'2026-09-01','starts_at'=>'08:00','ends_at'=>'18:00','facility_ids'=>[1],'slot_minutes'=>30])->assertStatus(422);
        $this->withToken('ops-setup')->getJson("/api/championships/{$championship->id}/operations")->assertOk()->assertJsonPath('championship.name','Club Champs');
    }

    public function test_auto_schedule_skips_conflicts_and_reports_placement_feedback(): void
    {
        $admin=User::factory()->create(['role'=>'hsc_super_admin','active'=>true,'api_token_hash'=>hash('sha256','ops-auto')]);
        $venueId=DB::table('venues')->insertGetId(['name'=>'HSC','code'=>'HSC','created_at'=>now(),'updated_at'=>now()]);
        $facility=Facility::create(['venue_id'=>$venueId,'name'=>'Court 1','code'=>'C1','facility_type'=>'court','active'=>true]);
        $championship=ClubChampionship::create(['name'=>'Club Champs','starts_on'=>'2026-09-01','ends_on'=>'2026-09-01','age_cutoff_on'=>'2026-09-01','convenor_user_id'=>$admin->id]);
        $event=ChampionshipEvent::create(['club_championship_id'=>$championship->id,'name'=>'Open singles','discipline'=>'singles','gender_rule'=>'open','draw_format'=>'knockout','entry_fee_cents'=>0,'fee_basis'=>'player','expected_match_minutes'=>60,'minimum_rest_minutes'=>0]);
        $entries=collect(range(1,2))->map(function($number)use($event){$member=Member::create(['member_number'=>'AUTO-'.$number,'first_name'=>'Player','last_name'=>(string)$number,'status'=>'active']);$entry=ChampionshipEntry::create(['championship_event_id'=>$event->id,'status'=>'accepted','payment_status'=>'paid']);$entry->members()->attach($member->id,['position'=>1,'confirmation_status'=>'confirmed']);return $entry;});
        $draw=ChampionshipDraw::create(['championship_event_id'=>$event->id,'version'=>1,'format'=>'knockout']);
        $match=ChampionshipMatch::create(['championship_draw_id'=>$draw->id,'round_number'=>1,'round_name'=>'Final','match_number'=>1,'entry1_id'=>$entries[0]->id,'entry2_id'=>$entries[1]->id,'status'=>'ready_to_schedule']);
        $playingSession=$championship->sessions()->create(['name'=>'Day 1','play_date'=>'2026-09-01','starts_at'=>'08:00','ends_at'=>'10:00','facility_ids'=>[$facility->id],'slot_minutes'=>60]);
        DB::table('bookings')->insert(['booking_reference'=>'BLOCK-1','facility_id'=>$facility->id,'created_by_user_id'=>$admin->id,'status'=>'confirmed','payment_status'=>'not_required','starts_at'=>'2026-09-01 06:00:00','ends_at'=>'2026-09-01 07:00:00','duration_minutes'=>60,'guest_count'=>0,'profile'=>'standard','currency'=>'ZAR','gross_amount'=>0,'allowance_amount'=>0,'discount_amount'=>0,'total_amount'=>0,'rule_snapshot'=>'{}','price_snapshot'=>'{}','cancellation_snapshot'=>'{}','confirmation_key'=>'auto-block','created_at'=>now(),'updated_at'=>now()]);

        $this->withToken('ops-auto')->postJson("/api/championships/{$championship->id}/operations/auto-schedule")
            ->assertOk()->assertJsonPath('scheduled',1)->assertJsonPath('unscheduled',0)->assertJsonPath('configuration.sessions',1);
        $this->assertSame('2026-09-01 07:00:00',$match->fresh()->scheduled_at->format('Y-m-d H:i:s'));
        $this->withToken('ops-auto')->patchJson("/api/championship-playing-sessions/{$playingSession->id}",['name'=>'Updated day','play_date'=>'2026-09-01','starts_at'=>'08:00','ends_at'=>'11:00','facility_ids'=>[$facility->id],'slot_minutes'=>30])->assertOk()->assertJsonPath('session.name','Updated day');
        $this->withToken('ops-auto')->deleteJson("/api/championship-playing-sessions/{$playingSession->id}")->assertOk();
        $this->assertDatabaseMissing('championship_playing_sessions',['id'=>$playingSession->id]);
    }
}
