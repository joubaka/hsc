<?php

namespace App\Services;

use App\Models\MembershipApplication;
use App\Models\SportMembership;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MembershipApplicationService
{
    private const TRANSITIONS = [
        'draft' => ['submit' => 'submitted', 'withdraw' => 'withdrawn', 'cancel' => 'cancelled'],
        'submitted' => ['begin_review' => 'under_review', 'withdraw' => 'withdrawn', 'cancel' => 'cancelled'],
        'under_review' => ['request_information' => 'information_required', 'approve' => 'approved', 'reject' => 'rejected', 'withdraw' => 'withdrawn'],
        'information_required' => ['submit' => 'submitted', 'withdraw' => 'withdrawn', 'cancel' => 'cancelled'],
    ];

    public function submit(MembershipApplication $application): MembershipApplication
    {
        abort_unless(in_array($application->status, ['draft', 'information_required'], true), 422, 'This application cannot be submitted.');
        $this->validateEligibility($application);
        $category = $application->category;
        $total = $category->code_fee_cents + $category->hsc_levy_cents + $application->joining_fee_cents - $application->discount_cents;
        throw_if($total < 0, ValidationException::withMessages(['discount' => 'Discount cannot exceed the application fees.']));
        $old = $application->getAttributes();
        $application->update([
            'status' => 'submitted', 'submitted_at' => now(), 'sport_code_fee_cents' => $category->code_fee_cents,
            'hsc_levy_cents' => $category->hsc_levy_cents, 'total_payable_cents' => $total,
            'billing_frequency' => $category->billing_period,
        ]);
        Audit::record('membership_application.submitted', $application, $old, $application->fresh()->getAttributes());

        return $application->fresh();
    }

    public function approve(int $id): MembershipApplication
    {
        return DB::transaction(function () use ($id) {
            $application = MembershipApplication::lockForUpdate()->with(['period', 'category', 'member'])->findOrFail($id);
            if ($application->status === 'approved') {
                return $application;
            }
            abort_unless(in_array($application->status, ['submitted', 'under_review'], true), 422, 'Application is not approvable.');
            abort_unless(request()->user()->canAccessSportCode($application->sport_code_id, true), 403);
            abort_unless(in_array($application->period->status, ['open', 'closed'], true), 422, 'The membership period cannot be approved.');
            abort_unless($application->category->active && $application->category->sport_code_id === $application->sport_code_id, 422, 'The selected category is no longer valid.');
            abort_if($application->sport_code_fee_cents === null || $application->hsc_levy_cents === null || $application->total_payable_cents === null, 422, 'The submitted fee snapshot is incomplete.');
            $this->validateEligibility($application);
            $membership = SportMembership::firstOrCreate(
                ['member_id' => $application->member_id, 'sport_code_id' => $application->sport_code_id, 'membership_period_id' => $application->membership_period_id],
                [
                    'membership_category_id' => $application->membership_category_id, 'source_application_id' => $application->id,
                    'status' => 'active', 'role' => 'member', 'joined_or_renewed_on' => now()->toDateString(),
                    'starts_on' => $application->period->starts_on, 'ends_on' => $application->period->ends_on,
                    'sport_code_fee_cents' => $application->sport_code_fee_cents, 'hsc_levy_cents' => $application->hsc_levy_cents,
                    'total_cents' => $application->total_payable_cents, 'billing_frequency' => $application->billing_frequency,
                ]
            );
            $application->update(['status' => 'approved', 'decided_at' => now(), 'decided_by_user_id' => request()->user()->id]);
            Audit::record('membership_application.approved', $application, ['status' => 'under_review'], ['status' => 'approved']);
            if ($membership->wasRecentlyCreated) {
                Audit::record($application->application_type === 'renewal' ? 'sport_membership.renewed' : 'sport_membership.created', $membership, [], $membership->getAttributes());
            }

            return $application->fresh(['sportMembership']);
        }, 3);
    }

    public function transition(MembershipApplication $application, string $action, ?string $reason = null, ?string $notes = null): MembershipApplication
    {
        if ($action === 'approve' && $application->status === 'approved') {
            return $this->approve($application->id);
        }
        $target = self::TRANSITIONS[$application->status][$action] ?? null;
        abort_unless($target, 422, 'Invalid application transition.');
        if ($action === 'submit') {
            return $this->submit($application->load(['member', 'period', 'category']));
        }
        if ($action === 'approve') {
            return $this->approve($application->id);
        }
        if ($action === 'reject') {
            abort_if(blank($reason), 422, 'A rejection reason is required.');
        }
        $old = $application->getAttributes();
        $updates = ['status' => $target];
        if ($target === 'information_required') {
            $updates['information_requested_at'] = now();
        }
        if ($target === 'rejected') {
            $updates += ['rejection_reason' => $reason, 'decided_at' => now(), 'decided_by_user_id' => request()->user()->id];
        }
        if ($target === 'withdrawn') {
            $updates['withdrawn_at'] = now();
        }
        if ($notes) {
            $updates['administrator_notes'] = $notes;
        }
        $application->update($updates);
        Audit::record("membership_application.$target", $application, $old, $updates);

        return $application->fresh();
    }

    public function validateEligibility(MembershipApplication $application): void
    {
        $age = $application->member->date_of_birth?->diffInYears($application->period->starts_on);
        if ($age !== null && $application->category->minimum_age !== null && $age < $application->category->minimum_age) {
            throw ValidationException::withMessages(['membership_category_id' => 'Member is below the minimum age at the period start.']);
        }
        if ($age !== null && $application->category->maximum_age !== null && $age > $application->category->maximum_age) {
            throw ValidationException::withMessages(['membership_category_id' => 'Member exceeds the maximum age at the period start.']);
        }
        if ($age !== null && $age < 18 && (! $application->guardian_consent || ! $application->guardian_name || ! $application->guardian_relationship || (! $application->guardian_email && ! $application->guardian_mobile))) {
            throw ValidationException::withMessages(['guardian' => 'A consenting guardian with usable contact information is required for a junior.']);
        }
        if ($age !== null && $age < 18 && $application->guardian_member_id) {
            $guardian = \App\Models\Member::find($application->guardian_member_id);
            if (! $guardian || $guardian->household_id !== $application->member->household_id || ! $guardian->date_of_birth || $guardian->date_of_birth->diffInYears($application->period->starts_on) < 18) {
                throw ValidationException::withMessages(['guardian_member_id' => 'The selected guardian must be an adult in the same household.']);
            }
        }
    }
}
