<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SportMembership extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_or_renewed_on' => 'date', 'starts_on' => 'date', 'ends_on' => 'date'];
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

    public function sourceApplication()
    {
        return $this->belongsTo(MembershipApplication::class, 'source_application_id');
    }
}
