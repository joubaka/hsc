<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\Member;
use App\Models\SportMembership;
use App\Models\User;
use Illuminate\Support\Carbon;

class BookingProfileResolver
{
    public function resolve(User $user, ?Member $member, Facility $facility, Carbon $when): array
    {
        $failures = [];
        if (! $member) {
            $failures[] = $this->failure('member_identity_required', 'Sign in with a linked member account to book.', false);
        } elseif ($member->status !== 'active') {
            $failures[] = $this->failure('member_inactive', 'Your central HSC membership is not active.', true);
        }
        $membership = $member && $facility->sport_code_id
            ? SportMembership::with('category')->where('member_id', $member->id)
                ->where('sport_code_id', $facility->sport_code_id)
                ->where('status', 'active')
                ->whereDate('starts_on', '<=', $when)
                ->whereDate('ends_on', '>=', $when)
                ->latest('starts_on')->first()
            : null;
        if ($facility->sport_code_id && ! $membership && ! $user->isCentralAdministrator()) {
            $failures[] = $this->failure('sport_membership_required', "An active {$facility->sportCode?->name} membership is required.", true);
        }
        $age = $member?->date_of_birth?->diffInYears($when);
        $category = strtolower($membership?->category?->name ?? '');
        $profile = match (true) {
            $user->isCentralAdministrator() => 'central_administrator',
            $facility->sport_code_id && $user->canAccessSportCode($facility->sport_code_id, true) => 'sport_administrator',
            str_contains($category, 'junior') || ($age !== null && $age < 18) => 'junior_member',
            str_contains($category, 'social') => 'social_member',
            str_contains($category, 'country') => 'country_member',
            default => 'adult_member',
        };

        return [
            'profile' => $profile, 'member_id' => $member?->id, 'age' => $age,
            'sport_membership_id' => $membership?->id, 'membership_category_id' => $membership?->membership_category_id,
            'failures' => $failures, 'eligible' => $failures === [],
        ];
    }

    private function failure(string $code, string $message, bool $override): array
    {
        return ['code' => $code, 'member_message' => $message, 'administrator_message' => $message, 'override_possible' => $override];
    }
}
