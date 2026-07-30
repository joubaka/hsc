<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AllocatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'exists:invoices,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
