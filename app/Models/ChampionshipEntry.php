<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class ChampionshipEntry extends Model {protected $guarded=[];protected function casts():array{return ['eligibility_snapshot'=>'array'];}public function event(){return $this->belongsTo(ChampionshipEvent::class,'championship_event_id');}public function members(){return $this->belongsToMany(Member::class,'championship_entry_members')->withPivot(['position','confirmation_status'])->withTimestamps();}}
