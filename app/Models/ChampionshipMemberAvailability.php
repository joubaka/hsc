<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChampionshipMemberAvailability extends Model { protected $table='championship_member_availability'; protected $guarded=[]; protected function casts():array{return ['unavailable_from'=>'datetime','unavailable_until'=>'datetime'];} public function member(){return $this->belongsTo(Member::class);} }
