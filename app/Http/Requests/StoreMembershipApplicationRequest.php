<?php

namespace App\Http\Requests;

use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\MembershipPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMembershipApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MembershipApplication::class) && $this->user()->canAccessSportCode($this->integer('sport_code_id'), true);
    }

    public function rules(): array
    {
        return ['member_id' => ['required', 'exists:members,id'], 'sport_code_id' => ['required', 'exists:sport_codes,id'], 'membership_category_id' => ['required', 'exists:membership_categories,id'], 'membership_period_id' => ['required', 'exists:membership_periods,id'], 'application_type' => ['required', Rule::in(['new_membership', 'additional_sport', 'renewal', 'category_change', 'reinstatement'])], 'applicant_notes' => ['nullable', 'string'], 'administrator_notes' => ['nullable', 'string'], 'joining_fee' => ['nullable', 'numeric', 'min:0'], 'discount' => ['nullable', 'numeric', 'min:0'], 'override_reason' => ['nullable', 'string', Rule::requiredIf(fn () => $this->float('discount') > 0)], 'guardian_member_id' => ['nullable', 'exists:members,id'], 'guardian_name' => ['nullable', 'string'], 'guardian_relationship' => ['nullable', 'string'], 'guardian_email' => ['nullable', 'email'], 'guardian_mobile' => ['nullable', 'string'], 'guardian_consent' => ['boolean'], 'independent_access_permitted' => ['boolean']];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $category = MembershipCategory::find($this->integer('membership_category_id'));
            if (! $category || $category->sport_code_id !== $this->integer('sport_code_id')) {
                $validator->errors()->add('membership_category_id', 'Category must belong to the selected sport code.');
            }
            $period = MembershipPeriod::find($this->integer('membership_period_id'));
            if (! $period || ! in_array($period->status, ['draft', 'open'], true)) {
                $validator->errors()->add('membership_period_id', 'The selected period is not accepting application work.');
            }
            if ($this->float('discount') > 0 && ! $this->user()->isCentralAdministrator()) {
                $validator->errors()->add('discount', 'Only central HSC may approve discounts.');
            }
        });
    }

    public function applicationData(): array
    {
        $data = $this->validated();
        if (! empty($data['guardian_member_id'])) {
            $guardian = \App\Models\Member::find($data['guardian_member_id']);
            $applicant = \App\Models\Member::find($data['member_id']);
            if (! $guardian || ! $applicant || $guardian->household_id === null || $guardian->household_id !== $applicant->household_id) {
                abort(422, 'Guardian must belong to the applicant household.');
            }
            $data['guardian_name'] = $guardian->display_name;
            $data['guardian_email'] = $guardian->email;
            $data['guardian_mobile'] = $guardian->mobile;
        }
        $data['joining_fee_cents'] = (int) round(($data['joining_fee'] ?? 0) * 100);
        unset($data['joining_fee']);
        $data['discount_cents'] = (int) round(($data['discount'] ?? 0) * 100);
        unset($data['discount']);

        return $data;
    }
}
