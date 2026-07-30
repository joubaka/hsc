<?php

namespace App\Policies;

use App\Models\MembershipPeriod;
use App\Models\User;

class MembershipPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MembershipPeriod $period): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isCentralAdministrator();
    }

    public function update(User $user, MembershipPeriod $period): bool
    {
        return $user->isCentralAdministrator() && $period->status !== 'archived';
    }

    public function managePeriods(User $user): bool
    {
        return $user->isCentralAdministrator();
    }
}
