<?php

namespace App\Policies;

use App\Models\SportMembership;
use App\Models\User;

class SportMembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SportMembership $membership): bool
    {
        return $user->canAccessSportCode($membership->sport_code_id);
    }

    public function createRenewalDrafts(User $user, SportMembership $membership): bool
    {
        return $user->canAccessSportCode($membership->sport_code_id, true);
    }
}
