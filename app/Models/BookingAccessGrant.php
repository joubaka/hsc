<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingAccessGrant extends Model
{
    protected $guarded = [];

    protected $hidden = ['encrypted_credential', 'credential_hash'];

    protected function casts(): array
    {
        return ['valid_from' => 'datetime', 'valid_until' => 'datetime', 'zone_snapshot' => 'array', 'encrypted_credential' => 'encrypted', 'provisioned_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function zone()
    {
        return $this->belongsTo(AccessZone::class, 'access_zone_id');
    }

    public function commands()
    {
        return $this->hasMany(AccessCommand::class);
    }
}
