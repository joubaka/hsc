<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return ['method' => ['nullable', 'in:member,booking_qr,facility_qr,short_code,administrator,late_administrator'], 'token' => ['nullable', 'string', 'max:128'], 'reason' => ['nullable', 'string', 'min:3', 'max:500']];
    }
}
