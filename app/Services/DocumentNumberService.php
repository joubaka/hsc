<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Models\FinancialSetting;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    public function next(string $type): string
    {
        $value = DB::transaction(function () use ($type) {
            DocumentSequence::firstOrCreate(['document_type' => $type], ['next_value' => 1]);
            $sequence = DocumentSequence::where('document_type', $type)->lockForUpdate()->firstOrFail();
            $value = $sequence->next_value;
            $sequence->increment('next_value');

            return $value;
        });

        $settings = FinancialSetting::firstOrCreate([])->fresh();
        $prefix = match ($type) {
            'invoice' => $settings->invoice_prefix,
            'receipt' => $settings->receipt_prefix,
            'credit_note' => $settings->credit_note_prefix,
            default => strtoupper($type).'-',
        };

        return $prefix.str_pad((string) $value, 7, '0', STR_PAD_LEFT);
    }
}
