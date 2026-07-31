<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LightingDevice extends Model
{
    protected $guarded = [];

    protected $hidden = ['username', 'password', 'provider_settings'];

    protected $appends = ['credentials_configured'];

    protected function casts(): array
    {
        return ['username' => 'encrypted', 'password' => 'encrypted', 'provider_settings' => 'encrypted:array', 'enabled' => 'boolean', 'dry_run' => 'boolean', 'last_health_checked_at' => 'datetime', 'last_seen_at' => 'datetime', 'use_tls' => 'boolean'];
    }

    public function channels()
    {
        return $this->hasMany(LightingDeviceChannel::class);
    }

    public function getCredentialsConfiguredAttribute(): bool
    {
        return filled($this->username) || filled($this->password);
    }

    protected static function booted(): void
    {
        static::deleting(fn (self $d) => $d->channels()->whereHas('commands')->exists() ? throw new \LogicException('Devices with command history cannot be deleted.') : null);
    }
}
