<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Booking, BookingSetting, ChampionshipAnnouncement, ChampionshipCourtBlock, ChampionshipMatch, ChampionshipMemberAvailability, ChampionshipPlayingSession, ChampionshipSchedulePublication, ClubChampionship, Facility, Member};
use App\Services\CommunicationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ChampionshipOperationsController extends Controller
{
    public function show(ClubChampionship $championship)
    {
        return response()->json([
            'championship' => $championship->load([
                'events.ageCategory','events.entries.members',
                'events.draws.matches.entry1.members','events.draws.matches.entry2.members','events.draws.matches.facility',
                'sessions','availability.member','courtBlocks.facility','schedulePublications','announcements',
            ]),
            'facilities' => Facility::where('active', true)->orderBy('name')->get(['id','name','sport_code_id']),
            'members' => Member::orderBy('last_name')->orderBy('first_name')->get(['id','first_name','last_name','preferred_name']),
        ]);
    }

    public function session(Request $request, ClubChampionship $championship)
    {
        $data = $this->validatedSession($request);
        return response()->json($championship->sessions()->create($data), 201);
    }

    public function updateSession(Request $request, ChampionshipPlayingSession $session)
    {
        $session->update($this->validatedSession($request));

        return response()->json(['message'=>'Playing session updated.','session'=>$session->fresh()]);
    }

    public function deleteSession(ChampionshipPlayingSession $session)
    {
        $session->delete();

        return response()->json(['message'=>'Playing session deleted.']);
    }

    public function availability(Request $request, ClubChampionship $championship)
    {
        $data = $request->validate(['member_id'=>'required|exists:members,id','unavailable_from'=>'required|date','unavailable_until'=>'required|date|after:unavailable_from','reason'=>'nullable|max:255']);
        return response()->json($championship->availability()->create($data), 201);
    }

    public function block(Request $request, ClubChampionship $championship)
    {
        $data = $request->validate(['facility_id'=>'required|exists:facilities,id','starts_at'=>'required|date','ends_at'=>'required|date|after:starts_at','reason'=>'required|max:255']);
        return response()->json($championship->courtBlocks()->create($data), 201);
    }

    public function schedule(Request $request, ChampionshipMatch $match)
    {
        $data = $request->validate(['scheduled_at'=>'required|date','facility_id'=>'required|exists:facilities,id']);
        $start = Carbon::parse($data['scheduled_at'], $this->bookingTimezone())->utc();
        $event = $match->draw->championshipEvent;
        $end = $start->copy()->addMinutes($event->expected_match_minutes ?: 90);
        $this->assertSchedulable($match, $start, $end, (int)$data['facility_id']);

        DB::transaction(function () use ($request, $match, $data, $start, $end, $event) {
            $booking = $match->booking ?: new Booking;
            $booking->fill([
                'booking_reference'=>$booking->booking_reference ?: 'CHAMP-'.Str::upper(Str::random(10)), 'facility_id'=>$data['facility_id'],
                'sport_code_id'=>Facility::find($data['facility_id'])?->sport_code_id, 'created_by_user_id'=>$request->user()->id,
                'status'=>'confirmed','payment_status'=>'not_required','starts_at'=>$start,'ends_at'=>$end,
                'duration_minutes'=>$event->expected_match_minutes ?: 90,'guest_count'=>0,'profile'=>'championship','currency'=>'ZAR',
                'gross_amount'=>0,'allowance_amount'=>0,'discount_amount'=>0,'total_amount'=>0,
                'rule_snapshot'=>['source'=>'club_championship','match_id'=>$match->id], 'price_snapshot'=>['total'=>0],
                'cancellation_snapshot'=>[],'confirmation_key'=>$booking->confirmation_key ?: Str::uuid()->toString(),
                'notes'=>'Club championship match '.$match->id,
                'cancelled_at'=>null,'cancelled_by_user_id'=>null,'cancellation_reason'=>null,
            ])->save();
            $match->update(['booking_id'=>$booking->id,'facility_id'=>$data['facility_id'],'scheduled_at'=>$start,'not_before_at'=>$start,'status'=>'scheduled']);
        });
        return $match->fresh(['entry1.members','entry2.members','facility']);
    }

    public function autoSchedule(Request $request, ClubChampionship $championship)
    {
        $matches = ChampionshipMatch::whereHas('draw.championshipEvent', fn($q)=>$q->where('club_championship_id',$championship->id))
            ->whereIn('status',['ready_to_schedule','postponed'])->whereNotNull('entry1_id')->whereNotNull('entry2_id')->get();
        $sessions=$championship->sessions()->orderBy('play_date')->get();
        $scheduled=0; $failures=[];
        foreach ($matches as $match) {
            $placed=false; $reasons=[];
            foreach ($sessions as $session) {
                $cursor=Carbon::parse($session->play_date->format('Y-m-d').' '.$session->starts_at,$this->bookingTimezone());
                $finish=Carbon::parse($session->play_date->format('Y-m-d').' '.$session->ends_at,$this->bookingTimezone());
                while ($cursor->copy()->addMinutes($match->draw->championshipEvent->expected_match_minutes ?: 90)->lte($finish)) {
                    foreach ($session->facility_ids as $facilityId) {
                        try {
                            $request->merge(['scheduled_at'=>$cursor->toIso8601String(),'facility_id'=>$facilityId]);
                            $this->schedule($request,$match); $scheduled++; $placed=true; break 3;
                        } catch (ValidationException $exception) {
                            $reasons=array_merge($reasons,$exception->validator->errors()->all());
                        } catch (HttpExceptionInterface $exception) {
                            if ($exception->getStatusCode() !== 422) throw $exception;
                            $reasons[]=$exception->getMessage();
                        }
                    }
                    $cursor->addMinutes($session->slot_minutes);
                }
            }
            if(!$placed)$failures[]=[
                'match_id'=>$match->id,
                'event'=>$match->draw->championshipEvent->name,
                'round'=>$match->round_name,
                'reasons'=>array_values(array_unique(array_filter($reasons))),
            ];
        }
        $unscheduled=count($failures);
        $message=$matches->isEmpty()
            ? 'There are no eligible matches in the scheduling queue.'
            : ($scheduled
                ? "Auto-scheduling placed {$scheduled} of {$matches->count()} queued matches".($unscheduled ? "; {$unscheduled} could not be placed." : '.')
                : 'No matches could be scheduled. Check that playing sessions and courts are configured, then review the reasons below.');

        return response()->json([
            'message'=>$message,
            'queued'=>$matches->count(),
            'scheduled'=>$scheduled,
            'unscheduled'=>$unscheduled,
            'failures'=>$failures,
            'configuration'=>['sessions'=>$sessions->count(),'courts'=>$sessions->pluck('facility_ids')->flatten()->unique()->count()],
        ]);
    }

    public function unschedule(ChampionshipMatch $match)
    {
        if ($match->booking) $match->booking->update(['status'=>'cancelled','cancelled_at'=>now(),'cancellation_reason'=>'Tournament match unscheduled']);
        $match->update(['booking_id'=>null,'facility_id'=>null,'scheduled_at'=>null,'not_before_at'=>null,'status'=>$match->entry1_id&&$match->entry2_id?'ready_to_schedule':'pending_players']);
        return $match->fresh();
    }

    public function desk(Request $request, ChampionshipMatch $match)
    {
        $action=$request->validate(['action'=>'required|in:checkin1,checkin2,call,start,suspend,resume,postpone,cancel'])['action'];
        $updates=match($action){
            'checkin1'=>['entry1_checked_in_at'=>now()], 'checkin2'=>['entry2_checked_in_at'=>now()],
            'call'=>['status'=>'called','called_at'=>now()], 'start'=>['status'=>'live','started_at'=>$match->started_at?:now()],
            'suspend'=>['status'=>'suspended'], 'resume'=>['status'=>'live'], 'postpone'=>['status'=>'postponed'], 'cancel'=>['status'=>'cancelled'],
        };
        $match->update($updates); return $match->fresh();
    }

    public function result(Request $request, ChampionshipMatch $match)
    {
        $data=$request->validate(['winner_entry_id'=>'required|integer','final_score'=>'required|max:255','score_data'=>'nullable|array','result_type'=>'nullable|in:played,walkover,retired,defaulted']);
        abort_unless(in_array((int)$data['winner_entry_id'],[(int)$match->entry1_id,(int)$match->entry2_id],true),422,'Winner must be a competitor in this match.');
        $dependent=ChampionshipMatch::where(fn($q)=>$q->where('source1_match_id',$match->id)->orWhere('source2_match_id',$match->id))->whereNotIn('status',['pending_players','ready_to_schedule'])->exists();
        abort_if($match->winner_entry_id && $dependent,422,'This result cannot be corrected after a dependent match has started scheduling.');
        $loser=(int)$data['winner_entry_id']===(int)$match->entry1_id?$match->entry2_id:$match->entry1_id;
        DB::transaction(function()use($match,$data,$loser){$match->update(['winner_entry_id'=>$data['winner_entry_id'],'loser_entry_id'=>$loser,'final_score'=>$data['final_score'],'score_data'=>$data['score_data']??null,'result_type'=>$data['result_type']??'played','result_status'=>'unverified','status'=>'completed','completed_at'=>now()]);$this->propagate($match);if($match->stage==='group')$this->resolveGroupQualifiers($match);});
        return $match->fresh(['entry1.members','entry2.members']);
    }

    public function verify(Request $request, ChampionshipMatch $match)
    {
        abort_unless($match->status==='completed'&&$match->winner_entry_id,422,'Record a completed result first.');
        $match->update(['result_status'=>'verified','result_verified_by_user_id'=>$request->user()->id,'result_verified_at'=>now()]); return $match;
    }

    public function publishSchedule(Request $request, ClubChampionship $championship)
    {
        $data=$request->validate(['play_date'=>'required|date','change_summary'=>'nullable|max:1000']);
        $matches=ChampionshipMatch::whereHas('draw.championshipEvent',fn($q)=>$q->where('club_championship_id',$championship->id))->whereDate('scheduled_at',$data['play_date'])->with(['draw.championshipEvent','entry1.members','entry2.members','facility'])->orderBy('scheduled_at')->get();
        abort_if($matches->isEmpty(),422,'There are no scheduled matches for this date.');
        $version=(int)$championship->schedulePublications()->whereDate('play_date',$data['play_date'])->max('version')+1;
        $snapshot=$matches->map(fn($m)=>['match_id'=>$m->id,'time'=>$m->scheduled_at->format('H:i'),'facility'=>$m->facility?->name,'event'=>$m->draw->championshipEvent->name,'round'=>$m->round_name,'competitors'=>[$m->entry1?->members->pluck('display_name')->join(' / '),$m->entry2?->members->pluck('display_name')->join(' / ')]]);
        DB::transaction(function()use($championship,$data,$version,$snapshot,$request){$championship->schedulePublications()->whereDate('play_date',$data['play_date'])->update(['is_current'=>false]);$championship->schedulePublications()->create(['play_date'=>$data['play_date'],'version'=>$version,'snapshot'=>$snapshot,'change_summary'=>$data['change_summary']??null,'is_current'=>true,'published_by_user_id'=>$request->user()->id,'published_at'=>now()]);});
        return response()->json(['version'=>$version,'matches'=>$snapshot]);
    }

    public function announcement(Request $request, ClubChampionship $championship, CommunicationService $communications)
    {
        $data=$request->validate(['title'=>'required|max:255','message'=>'required','audience'=>'required|in:all,competitors,today','priority'=>'required|in:normal,important,urgent','channels'=>'required|array|min:1','channels.*'=>'in:portal,email']);
        $announcement=$championship->announcements()->create($data+['created_by_user_id'=>$request->user()->id,'published_at'=>now()]);
        $emailQueued=false;$emailWarning=null;
        if(in_array('email',$data['channels'],true)){
            try{$memberIds=$this->entrantMemberIds($championship);$communications->announcement($request->user(),['name'=>$data['title'],'subject'=>$data['title'],'body'=>$data['message'],'audience_type'=>'selected','member_ids'=>$memberIds]);$emailQueued=true;}
            catch(ValidationException $exception){$emailWarning=$exception->getMessage();}
        }
        return response()->json(['announcement'=>$announcement,'email_queued'=>$emailQueued,'email_warning'=>$emailWarning],201);
    }

    private function assertSchedulable(ChampionshipMatch $match, Carbon $start, Carbon $end, int $facilityId): void
    {
        $event=$match->draw->championshipEvent;$championship=$event->championship;
        abort_unless($start->toDateString()>=$championship->starts_on->toDateString()&&$start->toDateString()<=$championship->ends_on->toDateString(),422,'Match falls outside tournament dates.');
        $conflict=Booking::where('facility_id',$facilityId)->whereNotIn('status',['cancelled','rejected'])->when($match->booking_id,fn($q)=>$q->where('id','!=',$match->booking_id))->where('starts_at','<',$end)->where('ends_at','>',$start)->exists();
        abort_if($conflict,422,'The court is already booked.');
        abort_if(ChampionshipCourtBlock::where('club_championship_id',$championship->id)->where('facility_id',$facilityId)->where('starts_at','<',$end)->where('ends_at','>',$start)->exists(),422,'The court is blocked.');
        $memberIds=$match->entry1->members->pluck('id')->merge($match->entry2->members->pluck('id'));
        abort_if(ChampionshipMemberAvailability::where('club_championship_id',$championship->id)->whereIn('member_id',$memberIds)->where('unavailable_from','<',$end)->where('unavailable_until','>',$start)->exists(),422,'A competitor is unavailable.');
        $restStart=$start->copy()->subMinutes($event->minimum_rest_minutes ?: 60);$restEnd=$end->copy()->addMinutes($event->minimum_rest_minutes ?: 60);
        $restConflict=ChampionshipMatch::where('id','!=',$match->id)->where(fn($q)=>$q->whereHas('entry1.members',fn($m)=>$m->whereIn('members.id',$memberIds))->orWhereHas('entry2.members',fn($m)=>$m->whereIn('members.id',$memberIds)));
        abort_if($restConflict->whereBetween('scheduled_at',[$restStart,$restEnd])->exists(),422,'A competitor requires more rest time.');
    }

    private function propagate(ChampionshipMatch $source): void
    {
        foreach(ChampionshipMatch::where('source1_match_id',$source->id)->orWhere('source2_match_id',$source->id)->get() as $next){$slot=$next->source1_match_id===$source->id?1:2;$outcome=$slot===1?$next->source1_outcome:$next->source2_outcome;$entry=str_contains((string)$outcome,'loser')?$source->loser_entry_id:$source->winner_entry_id;$next->update(["entry{$slot}_id"=>$entry,'status'=>($slot===1?$next->entry2_id:$next->entry1_id)?'ready_to_schedule':'pending_players']);}
    }

    private function resolveGroupQualifiers(ChampionshipMatch $source): void
    {
        $groupMatches=$source->draw->matches()->where('stage','group')->where('round_name',$source->round_name)->get();
        if($groupMatches->contains(fn($match)=>!$match->winner_entry_id))return;
        $table=[];
        foreach($groupMatches as $match){foreach([$match->entry1_id,$match->entry2_id] as $entryId)$table[$entryId]??=['won'=>0,'games_for'=>0,'games_against'=>0];$table[$match->winner_entry_id]['won']++;$pairs=[];preg_match_all('/(\d+)\s*[-–]\s*(\d+)/u',(string)$match->final_score,$pairs,PREG_SET_ORDER);foreach($pairs as $pair){$table[$match->entry1_id]['games_for']+=(int)$pair[1];$table[$match->entry1_id]['games_against']+=(int)$pair[2];$table[$match->entry2_id]['games_for']+=(int)$pair[2];$table[$match->entry2_id]['games_against']+=(int)$pair[1];}}
        uasort($table,fn($a,$b)=>[$b['won'],$b['games_for']-$b['games_against']]<=>[$a['won'],$a['games_for']-$a['games_against']]);$ranked=array_keys($table);
        foreach($source->draw->matches()->where('stage','elimination')->where('round_number',1)->get() as $target){foreach([1,2] as $slot){$label=$slot===1?$target->source1_outcome:$target->source2_outcome;if(preg_match('/^'.preg_quote($source->round_name,'/').' #(\d+)$/',$label,$found))$target->update(["entry{$slot}_id"=>$ranked[(int)$found[1]-1]??null]);}$target->refresh();if($target->entry1_id&&$target->entry2_id)$target->update(['status'=>'ready_to_schedule']);}
    }

    private function entrantMemberIds(ClubChampionship $championship): array
    { return DB::table('championship_entry_members as cem')->join('championship_entries as ce','ce.id','=','cem.championship_entry_id')->join('championship_events as ev','ev.id','=','ce.championship_event_id')->where('ev.club_championship_id',$championship->id)->distinct()->pluck('cem.member_id')->all(); }

    private function validatedSession(Request $request): array
    {
        return $request->validate(['name'=>'required|max:100','play_date'=>'required|date','starts_at'=>'required','ends_at'=>'required|after:starts_at','facility_ids'=>'required|array|min:1','facility_ids.*'=>'exists:facilities,id','slot_minutes'=>'required|integer|min:15|max:240']);
    }

    private function bookingTimezone(): string
    {
        return BookingSetting::query()->value('timezone') ?: 'Africa/Johannesburg';
    }
}
