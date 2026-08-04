<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class ChampionshipEvent extends Model {protected $guarded=[];protected function casts():array{return ['ranking_rules'=>'array'];}public function championship(){return $this->belongsTo(ClubChampionship::class,'club_championship_id');}public function ageCategory(){return $this->belongsTo(ChampionshipAgeCategory::class,'age_category_id');}public function entries(){return $this->hasMany(ChampionshipEntry::class);}public function draws(){return $this->hasMany(ChampionshipDraw::class);} }
