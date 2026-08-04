<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChampionshipSchedulePublication extends Model { protected $guarded=[]; protected function casts():array{return ['play_date'=>'date','snapshot'=>'array','is_current'=>'boolean','published_at'=>'datetime'];} }
