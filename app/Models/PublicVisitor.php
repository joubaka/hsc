<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicVisitor extends Model
{
    protected $guarded = [];

    protected $hidden = ['session_token_hash', 'email', 'mobile'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'mobile_verified_at' => 'datetime', 'terms_accepted_at' => 'datetime', 'privacy_accepted_at' => 'datetime', 'marketing_consent' => 'boolean', 'last_booking_at' => 'datetime', 'session_expires_at' => 'datetime'];
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function challenges()
    {
        return $this->hasMany(VisitorVerificationChallenge::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
