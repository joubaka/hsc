<?php

namespace App\Services;

use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;

class FinancialReportService
{
    public function statement(User $user, int $memberId): array
    {
        $transactions = FinancialTransaction::where('member_id', $memberId)->orderBy('occurred_on')->orderBy('id')->get();
        if (! $user->isCentralAdministrator()) {
            $allowed = $user->sportCodes()->pluck('sport_codes.id');
            $transactions = $transactions->filter(fn ($row) => $row->sport_code_id && $allowed->contains($row->sport_code_id))->values();
        }
        $balance = 0;
        $rows = $transactions->map(function ($transaction) use (&$balance) {
            $effect = match ($transaction->transaction_type) {
                'invoice_charge' => $transaction->amount,
                'payment_allocation', 'allocation_reversal', 'credit_note', 'write_off', 'invoice_void' => -$transaction->amount,
                default => 0,
            };
            $balance += $effect;

            return [...$transaction->toArray(), 'balance' => $balance];
        });

        return ['opening_balance' => 0, 'transactions' => $rows, 'closing_balance' => $balance];
    }

    public function outstanding(User $user): array
    {
        $query = Invoice::with(['member:id,member_number,first_name,last_name', 'lines:id,invoice_id,sport_code_id,financial_owner_type'])
            ->where('outstanding_amount', '>', 0)->whereNotIn('status', ['draft', 'void']);
        $invoices = $query->get()->filter(fn ($invoice) => app(FinanceAccessService::class)->canViewInvoice($user, $invoice))->values();

        return $invoices->map(fn ($invoice) => [
            ...$invoice->only(['id', 'invoice_number', 'member_id', 'issued_on', 'due_on', 'currency', 'total_amount', 'paid_amount', 'credited_amount', 'written_off_amount', 'outstanding_amount', 'status']),
            'member' => $invoice->member,
            'sport_code_ids' => $invoice->lines->pluck('sport_code_id')->filter()->unique()->values(),
            'days_overdue' => $invoice->due_on?->isPast() ? $invoice->due_on->diffInDays(now()) : 0,
        ])->all();
    }

    public function sportCode(User $user, int $sportCodeId): array
    {
        abort_unless($user->canAccessSportCode($sportCodeId), 403);
        $invoiced = (int) \App\Models\InvoiceLine::where('sport_code_id', $sportCodeId)->where('financial_owner_type', 'sport_code')->sum('total_amount');
        $collected = (int) FinancialTransaction::where('sport_code_id', $sportCodeId)->where('transaction_type', 'payment_allocation')->sum('amount')
            + (int) FinancialTransaction::where('sport_code_id', $sportCodeId)->where('transaction_type', 'allocation_reversal')->sum('amount');
        $credits = (int) FinancialTransaction::where('sport_code_id', $sportCodeId)->where('transaction_type', 'credit_note')->sum('amount');
        $writeOffs = (int) FinancialTransaction::where('sport_code_id', $sportCodeId)->where('transaction_type', 'write_off')->sum('amount');

        return compact('sportCodeId', 'invoiced', 'collected', 'credits', 'writeOffs') + ['outstanding' => $invoiced - $collected - $credits - $writeOffs];
    }

    public function levy(User $user): array
    {
        abort_unless($user->isCentralAdministrator(), 403);
        $invoiced = (int) \App\Models\InvoiceLine::where('financial_owner_type', 'central_hsc')->sum('total_amount');
        $collected = (int) FinancialTransaction::where('financial_owner_type', 'central_hsc')->where('transaction_type', 'payment_allocation')->sum('amount')
            + (int) FinancialTransaction::where('financial_owner_type', 'central_hsc')->where('transaction_type', 'allocation_reversal')->sum('amount');
        $credits = (int) FinancialTransaction::where('financial_owner_type', 'central_hsc')->where('transaction_type', 'credit_note')->sum('amount');
        $writeOffs = (int) FinancialTransaction::where('financial_owner_type', 'central_hsc')->where('transaction_type', 'write_off')->sum('amount');

        return compact('invoiced', 'collected', 'credits', 'writeOffs') + ['outstanding' => $invoiced - $collected - $credits - $writeOffs];
    }
}
