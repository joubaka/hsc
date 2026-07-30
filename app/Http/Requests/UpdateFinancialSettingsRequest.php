<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFinancialSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isCentralAdministrator();
    }

    public function rules(): array
    {
        return [
            'default_currency' => ['required', 'string', 'size:3'],
            'invoice_prefix' => ['required', 'string', 'max:20'],
            'receipt_prefix' => ['required', 'string', 'max:20'],
            'credit_note_prefix' => ['required', 'string', 'max:20'],
            'financial_year_start_month' => ['required', 'integer', 'between:1,12'],
            'default_invoice_due_days' => ['required', 'integer', 'between:0,365'],
            'banking_reference_instructions' => ['nullable', 'string', 'max:2000'],
            'payment_reference_format' => ['required', 'string', 'max:255'],
            'joining_fee_owner_type' => ['required', Rule::in(['sport_code', 'central_hsc'])],
            'generate_zero_value_invoices' => ['required', 'boolean'],
        ];
    }
}
