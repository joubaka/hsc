<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'protected_account',
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
            'protected_account' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function sportCodes(): BelongsToMany
    {
        return $this->belongsToMany(SportCode::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
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

    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if (! $user->getOriginal('protected_account')) {
                return;
            }

            if (
                $user->isDirty('email')
                || $user->role !== 'hsc_super_admin'
                || ! $user->active
                || ! $user->protected_account
            ) {
                throw new \LogicException('The protected HSC super-administrator account cannot be disabled, demoted, or renamed.');
            }
        });

        static::deleting(function (User $user) {
            if ($user->protected_account) {
                throw new \LogicException('The protected HSC super-administrator account cannot be deleted.');
            }
        });
    }
}
