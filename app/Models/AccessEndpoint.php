<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessEndpoint extends Model
{
    protected $guarded = [];

    protected $hidden = ['endpoint_host', 'username', 'password', 'provider_settings'];

    protected $appends = ['credentials_configured'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'dry_run' => 'boolean', 'username' => 'encrypted', 'password' => 'encrypted', 'provider_settings' => 'encrypted:array', 'last_health_checked_at' => 'datetime'];
    }

    public function zone()
    {
        return $this->belongsTo(AccessZone::class, 'access_zone_id');
    }

    public function getCredentialsConfiguredAttribute(): bool
    {
        return filled($this->username) || filled($this->password);
    }
}
