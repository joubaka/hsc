<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationDelivery extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime', 'read_at' => 'datetime']; }
    public function campaign() { return $this->belongsTo(CommunicationCampaign::class, 'communication_campaign_id'); }
    public function member() { return $this->belongsTo(Member::class); }
    public function related() { return $this->morphTo(); }
}
