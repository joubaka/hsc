<?php

namespace App\Http\Requests;

use App\Models\Member;

class UpdateMembershipApplicationRequest extends StoreMembershipApplicationRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('membershipApplication'));
    }

    public function rules(): array
    {
        return [
            'applicant_notes' => ['nullable', 'string'],
            'guardian_member_id' => ['nullable', 'exists:members,id'],
            'guardian_name' => ['nullable', 'string'],
            'guardian_relationship' => ['nullable', 'string'],
            'guardian_email' => ['nullable', 'email'],
            'guardian_mobile' => ['nullable', 'string'],
            'guardian_consent' => ['boolean'],
            'independent_access_permitted' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('guardian_member_id')) {
                return;
            }

            $guardian = Member::find($this->integer('guardian_member_id'));
            $applicant = $this->route('membershipApplication')?->member;
            if (! $guardian || ! $applicant || $guardian->household_id === null || $guardian->household_id !== $applicant->household_id) {
                $validator->errors()->add('guardian_member_id', 'Guardian must belong to the applicant household.');
            }
        });
    }

    public function applicationData(): array
    {
        $data = $this->validated();
        if (! empty($data['guardian_member_id'])) {
            $guardian = Member::findOrFail($data['guardian_member_id']);
            $data['guardian_name'] = $guardian->display_name;
            $data['guardian_email'] = $guardian->email;
            $data['guardian_mobile'] = $guardian->mobile;
        }

        return $data;
    }
}
