<?php

namespace App\Services;

use App\Models\Invoice;

class InvoiceReconciliationService
{
    public function reconcile(Invoice $invoice): Invoice
    {
        $invoice->refresh();
        $paid = (int) $invoice->allocations()->whereNull('reversed_at')->sum('amount');
        $credited = (int) $invoice->creditNotes()->whereIn('status', ['issued', 'applied', 'partially_applied'])->sum('applied_amount');
        $writtenOff = (int) $invoice->transactions()->where('transaction_type', 'write_off')->sum('amount');
        $outstanding = max(0, $invoice->total_amount - $paid - $credited - $writtenOff);

        $status = $invoice->status;
        if (! in_array($status, ['draft', 'void'], true)) {
            $status = match (true) {
                $outstanding === 0 && $paid > $invoice->total_amount => 'overpaid',
                $outstanding === 0 && $writtenOff > 0 => 'written_off',
                $outstanding === 0 => 'paid',
                $paid > 0 || $credited > 0 || $writtenOff > 0 => 'partially_paid',
                default => 'issued',
            };
        }

        $invoice->forceFill([
            'paid_amount' => $paid,
            'credited_amount' => $credited,
            'written_off_amount' => $writtenOff,
            'outstanding_amount' => $outstanding,
            'status' => $status,
        ])->save();

        return $invoice->fresh();
    }
}
