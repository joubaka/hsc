<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLightingTariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('lightingZone'));
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:120', 'pricing_method' => 'required|in:fixed,hourly,per_minute', 'amount_cents' => 'required|integer|min:0|max:1000000', 'profile' => 'nullable|string|max:40', 'starts_at' => 'nullable|date_format:H:i', 'ends_at' => 'nullable|date_format:H:i|after:starts_at', 'minimum_minutes' => 'required|integer|between:1,240', 'increment_minutes' => 'required|integer|between:1,60', 'financial_owner_type' => 'required|in:sport_code,central_hsc', 'active' => 'required|boolean', 'priority' => 'required|integer|between:0,1000'];
    }
}
