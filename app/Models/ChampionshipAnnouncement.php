<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChampionshipAnnouncement extends Model { protected $guarded=[]; protected function casts():array{return ['channels'=>'array','published_at'=>'datetime'];} }
