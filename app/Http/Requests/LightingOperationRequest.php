<?php

namespace App\Http\Requests;

use App\Models\LightingSetting;
use Illuminate\Foundation\Http\FormRequest;

class LightingOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->active && $this->user()->isCentralAdministrator();
    }

    public function rules(): array
    {
        $maximum = LightingSetting::whereNull('sport_code_id')->value('manual_override_max_minutes') ?? 120;

        return ['reason' => 'required|string|min:3|max:500', 'state' => 'sometimes|in:on,off', 'minutes' => "sometimes|integer|between:1,{$maximum}", 'idempotency_key' => 'nullable|uuid'];
    }
}
