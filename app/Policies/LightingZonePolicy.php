<?php

namespace App\Policies;

use App\Models\LightingZone;
use App\Models\User;

class LightingZonePolicy
{
    public function before(User $u): ?bool
    {
        return $u->active ? null : false;
    }

    public function viewAny(User $u): bool
    {
        return $u->isCentralAdministrator() || $u->sportCodes()->exists();
    }

    public function create(User $u): bool
    {
        return $u->isCentralAdministrator() || $u->sportCodes()->wherePivotIn('role', ['administrator', 'committee'])->exists();
    }

    public function update(User $u, LightingZone $z): bool
    {
        return $u->isCentralAdministrator() || $u->canAccessSportCode($z->sport_code_id, true);
    }
}
