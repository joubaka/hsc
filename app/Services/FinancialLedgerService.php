<?php

namespace App\Services;

use App\Models\FinancialTransaction;
use App\Models\Invoice;

class FinancialLedgerService
{
    public function recordInvoiceOwners(Invoice $invoice, string $type, int $amount, string $keyPrefix, ?string $reason = null, ?int $paymentId = null, ?int $creditNoteId = null): void
    {
        $owners = $invoice->lines()->get()
            ->groupBy(fn ($line) => $line->financial_owner_type.':'.($line->sport_code_id ?: 'central'))
            ->map(fn ($lines) => [
                'financial_owner_type' => $lines->first()->financial_owner_type,
                'sport_code_id' => $lines->first()->sport_code_id,
                'amount' => (int) $lines->sum('total_amount'),
            ])->values();
        $base = max(1, (int) $owners->sum(fn ($owner) => max(0, $owner['amount'])));
        $sign = $amount < 0 ? -1 : 1;
        $absoluteAmount = abs($amount);
        $remaining = $absoluteAmount;

        foreach ($owners as $index => $owner) {
            $share = $index === $owners->count() - 1
                ? $remaining
                : min($remaining, intdiv($absoluteAmount * max(0, $owner['amount']), $base));
            if ($share <= 0) {
                continue;
            }
            $remaining -= $share;
            FinancialTransaction::firstOrCreate(['idempotency_key' => "$keyPrefix:owner:$index"], [
                'member_id' => $invoice->member_id,
                'invoice_id' => $invoice->id,
                'payment_id' => $paymentId,
                'credit_note_id' => $creditNoteId,
                'membership_period_id' => $invoice->membership_period_id,
                'sport_code_id' => $owner['sport_code_id'],
                'financial_owner_type' => $owner['financial_owner_type'],
                'transaction_type' => $type,
                'currency' => $invoice->currency,
                'amount' => $share * $sign,
                'occurred_on' => now()->toDateString(),
                'reference' => $invoice->invoice_number ?: "DRAFT-{$invoice->id}",
                'reason' => $reason,
                'created_by_user_id' => auth()->id(),
            ]);
        }
    }
}
