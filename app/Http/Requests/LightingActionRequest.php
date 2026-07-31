<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LightingActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->active && $this->user()->isCentralAdministrator();
    }

    public function rules(): array
    {
        return [];
    }
}
