<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'member_id',
        'role',
        'active',
        'last_login_at',
        'api_token_hash',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_token_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function sportCodes(): BelongsToMany
    {
        return $this->belongsToMany(SportCode::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function isCentralAdministrator(): bool
    {
        return in_array($this->role, ['hsc_super_admin', 'hsc_admin'], true);
    }

    public function isSuperAdministrator(): bool
    {
        return $this->role === 'hsc_super_admin';
    }

    public function canAccessSportCode(int $sportCodeId, bool $write = false): bool
    {
        if ($this->isCentralAdministrator()) {
            return true;
        }

        return $this->sportCodes()
            ->where('sport_codes.id', $sportCodeId)
            ->when($write, fn ($query) => $query->whereIn('sport_code_user.role', ['administrator', 'committee']))
            ->exists();
    }
}
