<?php

namespace App\Policies;

use App\Models\LightingDevice;
use App\Models\User;

class LightingDevicePolicy
{
    public function before(User $u): ?bool
    {
        return $u->active ? null : false;
    }

    public function viewAny(User $u): bool
    {
        return $u->isCentralAdministrator();
    }

    public function create(User $u): bool
    {
        return $u->isSuperAdministrator();
    }

    public function update(User $u, LightingDevice $d): bool
    {
        return $u->isSuperAdministrator();
    }

    public function operate(User $u, LightingDevice $d): bool
    {
        return $u->isCentralAdministrator();
    }
}
