<?php

namespace App\Http\Requests;

class UpdateMembershipPeriodRequest extends StoreMembershipPeriodRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('membershipPeriod'));
    }
}
