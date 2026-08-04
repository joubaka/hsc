<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationCampaign extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['audience_filter' => 'array', 'scheduled_at' => 'datetime', 'sent_at' => 'datetime']; }
    public function deliveries() { return $this->hasMany(CommunicationDelivery::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
