<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmergencyAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active === true;
    }

    public function rules(): array
    {
        return ['scope_type' => ['required', 'in:endpoint,zone,venue,sport_code,all_temporary'], 'scope_id' => ['nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:8', 'max:500'], 'idempotency_key' => ['required', 'uuid']];
    }
}
