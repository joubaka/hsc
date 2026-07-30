<?php

namespace App\Http\Requests;

use App\Models\MembershipPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMembershipPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MembershipPeriod::class);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'], 'applications_open_on' => ['nullable', 'date', 'before_or_equal:applications_close_on'], 'applications_close_on' => ['nullable', 'date', 'before_or_equal:ends_on'], 'renewal_deadline' => ['nullable', 'date', 'before_or_equal:ends_on'], 'status' => ['required', Rule::in(['draft', 'open', 'closed'])]];
    }
}
