<?php

namespace App\Services;

use App\Models\MembershipApplication;
use App\Models\MembershipPeriod;
use App\Models\SportMembership;
use App\Models\User;
use App\Support\Audit;

class RenewalService
{
    public function dashboard(User $user, MembershipPeriod $target, int $sportCodeId): array
    {
        abort_unless($user->canAccessSportCode($sportCodeId), 403);
        $previous = MembershipPeriod::where('ends_on', '<', $target->starts_on)->orderByDesc('ends_on')->first();
        $eligible = $previous ? SportMembership::with(['member', 'category'])->where('membership_period_id', $previous->id)->where('sport_code_id', $sportCodeId)->whereIn('status', ['active', 'expired'])->get()->unique('member_id') : collect();
        $applications = MembershipApplication::where('membership_period_id', $target->id)->where('sport_code_id', $sportCodeId)->where('application_type', 'renewal')->get()->groupBy('member_id');
        $current = SportMembership::where('membership_period_id', $target->id)->where('sport_code_id', $sportCodeId)->pluck('member_id')->flip();
        $rows = $eligible->map(function ($membership) use ($applications, $current) {
            $apps = $applications->get($membership->member_id, collect());
            $latest = $apps->sortByDesc('id')->first();

            return ['member' => $membership->member, 'previous_membership' => $membership, 'status' => $current->has($membership->member_id) ? 'approved' : ($latest?->status ?? 'not_started')];
        })->values();
        $counts = ['eligible' => $rows->count()];
        foreach (['not_started', 'draft', 'submitted', 'under_review', 'information_required', 'approved', 'rejected'] as $status) {
            $counts[$status] = $rows->where('status', $status)->count();
        }

        return compact('counts', 'rows', 'previous');
    }

    public function bulkDrafts(User $user, MembershipPeriod $target, int $sportCodeId): array
    {
        abort_unless($user->canAccessSportCode($sportCodeId, true), 403);
        $dashboard = $this->dashboard($user, $target, $sportCodeId);
        $results = [];
        foreach ($dashboard['rows'] as $row) {
            $member = $row['member'];
            $previous = $row['previous_membership'];
            if ($row['status'] === 'approved') {
                $results[] = ['member_id' => $member->id, 'outcome' => 'already_renewed'];

                continue;
            }
            if ($row['status'] !== 'not_started') {
                $results[] = ['member_id' => $member->id, 'outcome' => 'already_exists'];

                continue;
            }
            if (! $previous->category?->active) {
                $results[] = ['member_id' => $member->id, 'outcome' => 'ineligible'];

                continue;
            }
            MembershipApplication::create(['member_id' => $member->id, 'sport_code_id' => $sportCodeId, 'membership_category_id' => $previous->membership_category_id, 'membership_period_id' => $target->id, 'application_type' => 'renewal', 'status' => 'draft']);
            $results[] = ['member_id' => $member->id, 'outcome' => 'created'];
        }
        Audit::record('renewals.bulk_drafts_created', $target, [], ['sport_code_id' => $sportCodeId, 'results' => $results]);

        return $results;
    }
}
