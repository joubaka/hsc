<?php

namespace App\Http\Requests;

class UpdateMembershipApplicationRequest extends StoreMembershipApplicationRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('membershipApplication'));
    }
}
