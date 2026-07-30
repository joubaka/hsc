<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isCentralAdministrator() || $this->user()->sportCodes()->whereIn('sport_code_user.role', ['administrator', 'committee'])->exists();
    }

    public function rules(): array
    {
        return [
            'payment_reference' => ['nullable', 'string', 'max:100', 'unique:payments,payment_reference'],
            'member_id' => ['nullable', 'exists:members,id'],
            'payer_name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'integer', 'min:1'],
            'received_on' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(['eft', 'cash', 'card', 'bank_deposit', 'debit_order', 'imported_bank_transaction', 'other'])],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'bank_reference' => ['nullable', 'string', 'max:255'],
            'import_fingerprint' => ['nullable', 'string', 'max:255', 'unique:payments,import_fingerprint'],
            'source' => ['required', Rule::in(['manual', 'csv_import'])],
        ];
    }
}
