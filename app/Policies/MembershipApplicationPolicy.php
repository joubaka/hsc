<?php

namespace App\Policies;

use App\Models\MembershipApplication;
use App\Models\User;

class MembershipApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MembershipApplication $application): bool
    {
        return $user->canAccessSportCode($application->sport_code_id);
    }

    public function create(User $user): bool
    {
        return $user->isCentralAdministrator() || $user->sportCodes()->whereIn('sport_code_user.role', ['administrator', 'committee'])->exists();
    }

    public function update(User $user, MembershipApplication $application): bool
    {
        return $user->canAccessSportCode($application->sport_code_id, true) && in_array($application->status, ['draft', 'information_required'], true);
    }

    public function submit(User $user, MembershipApplication $application): bool
    {
        return $this->update($user, $application);
    }

    public function beginReview(User $user, MembershipApplication $application): bool
    {
        return $user->canAccessSportCode($application->sport_code_id, true) && $application->status === 'submitted';
    }

    public function requestInformation(User $user, MembershipApplication $application): bool
    {
        return $user->canAccessSportCode($application->sport_code_id, true) && $application->status === 'under_review';
    }

    public function resubmit(User $user, MembershipApplication $application): bool
    {
        return $user->canAccessSportCode($application->sport_code_id, true) && $application->status === 'information_required';
    }

    public function approve(User $user, MembershipApplication $application): bool
    {
        return $user->canAccessSportCode($application->sport_code_id, true) && in_array($application->status, ['submitted', 'under_review', 'approved'], true);
    }

    public function reject(User $user, MembershipApplication $application): bool
    {
        return $user->canAccessSportCode($application->sport_code_id, true) && $application->status === 'under_review';
    }

    public function withdraw(User $user, MembershipApplication $application): bool
    {
        return $user->canAccessSportCode($application->sport_code_id, true) && in_array($application->status, ['draft', 'submitted', 'under_review', 'information_required'], true);
    }

    public function cancel(User $user, MembershipApplication $application): bool
    {
        return $user->canAccessSportCode($application->sport_code_id, true) && in_array($application->status, ['draft', 'submitted', 'information_required'], true);
    }
}
