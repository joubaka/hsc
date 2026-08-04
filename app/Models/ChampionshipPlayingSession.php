<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChampionshipPlayingSession extends Model { protected $guarded=[]; protected function casts():array{return ['play_date'=>'date','facility_ids'=>'array'];} public function championship(){return $this->belongsTo(ClubChampionship::class,'club_championship_id');} }
