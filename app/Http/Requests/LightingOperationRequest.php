<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LightingOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->active && $this->user()->isCentralAdministrator();
    }

    public function rules(): array
    {
        return ['reason' => 'required|string|min:3|max:500', 'state' => 'sometimes|in:on,off', 'minutes' => 'sometimes|integer|between:1,480'];
    }
}
