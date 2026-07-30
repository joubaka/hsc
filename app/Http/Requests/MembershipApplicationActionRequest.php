<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipApplicationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = ['submit' => 'submit', 'begin_review' => 'beginReview', 'request_information' => 'requestInformation', 'approve' => 'approve', 'reject' => 'reject', 'withdraw' => 'withdraw', 'cancel' => 'cancel'][$this->input('action')] ?? 'view';

        return $this->user()->can($ability, $this->route('membershipApplication'));
    }

    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['submit', 'begin_review', 'request_information', 'approve', 'reject', 'withdraw', 'cancel'])], 'reason' => ['nullable', 'string', 'max:2000', Rule::requiredIf($this->input('action') === 'reject')], 'administrator_notes' => ['nullable', 'string', 'max:2000']];
    }
}
