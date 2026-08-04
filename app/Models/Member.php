<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $appends = ['display_name', 'age'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'email_consent' => 'boolean',
            'whatsapp_consent' => 'boolean',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function sportCodes(): BelongsToMany
    {
        return $this->belongsToMany(SportCode::class)
            ->withPivot(['membership_category_id', 'status', 'role', 'joined_at', 'ended_at'])
            ->withTimestamps();
    }

    public function accessCredential(): HasOne
    {
        return $this->hasOne(AccessCredential::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(($this->preferred_name ?: $this->first_name).' '.$this->last_name);
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth?->age;
    }
}
