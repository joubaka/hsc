<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipApplication extends Model
{
    protected $guarded = [];

    protected $appends = ['fee_total'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime', 'information_requested_at' => 'datetime', 'decided_at' => 'datetime',
            'withdrawn_at' => 'datetime', 'guardian_consent' => 'boolean', 'independent_access_permitted' => 'boolean',
        ];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function sportCode()
    {
        return $this->belongsTo(SportCode::class);
    }

    public function category()
    {
        return $this->belongsTo(MembershipCategory::class, 'membership_category_id');
    }

    public function period()
    {
        return $this->belongsTo(MembershipPeriod::class, 'membership_period_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function sportMembership()
    {
        return $this->hasOne(SportMembership::class, 'source_application_id');
    }

    public function getFeeTotalAttribute(): ?float
    {
        return $this->total_payable_cents === null ? null : $this->total_payable_cents / 100;
    }
}
