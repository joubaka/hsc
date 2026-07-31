<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLightingPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lightingZone'));
    }

    public function rules(): array
    {
        return ['strategy' => 'required|in:manual,fixed_band,always_required,never_available', 'starts_at' => 'nullable|required_if:strategy,fixed_band|date_format:H:i', 'ends_at' => 'nullable|required_if:strategy,fixed_band|date_format:H:i|after:starts_at', 'profile' => 'nullable|string|max:40', 'active' => 'required|boolean', 'priority' => 'required|integer|between:0,1000'];
    }
}
