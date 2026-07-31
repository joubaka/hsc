<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\User;

class BookingAccessService
{
    public function canView(User $user, Facility $facility): bool
    {
        return $facility->sport_code_id === null || $user->canAccessSportCode($facility->sport_code_id);
    }

    public function canManage(User $user, Facility $facility): bool
    {
        return $user->isCentralAdministrator()
            || ($facility->sport_code_id && $user->canAccessSportCode($facility->sport_code_id, true));
    }
}
