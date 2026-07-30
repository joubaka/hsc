<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionMembershipPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('managePeriods', \App\Models\MembershipPeriod::class);
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['open', 'closed', 'archived'])]];
    }
}
