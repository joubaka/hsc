<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private InvoiceReconciliationService $reconciliation,
        private FinancialLedgerService $ledger,
    ) {}

    public function record(User $user, array $data): Payment
    {
        abort_unless($user->isCentralAdministrator() || $user->sportCodes()->whereIn('sport_code_user.role', ['administrator', 'committee'])->exists(), 403);
        abort_unless((int) $data['amount'] > 0, 422, 'Payment amount must be positive.');

        $payment = Payment::create([
            ...$data,
            'payment_reference' => $data['payment_reference'] ?: 'PAY-'.str()->upper(str()->random(12)),
            'status' => 'pending',
            'allocated_amount' => 0,
            'unallocated_amount' => (int) $data['amount'],
            'recorded_by_user_id' => $user->id,
        ]);
        Audit::record('payment.recorded', $payment, [], ['member_id' => $payment->member_id, 'amount' => $payment->amount, 'currency' => $payment->currency, 'source' => $payment->source]);

        return $payment;
    }

    public function confirm(User $user, Payment $payment): Payment
    {
        abort_unless($user->isCentralAdministrator() || $user->sportCodes()->whereIn('sport_code_user.role', ['administrator', 'committee'])->exists(), 403);
        abort_unless($payment->status === 'pending', 422, 'Only pending payments may be confirmed.');
        $receiptNumber = $this->numbers->next('receipt');

        return DB::transaction(function () use ($user, $payment, $receiptNumber) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $payment->update(['status' => 'confirmed', 'confirmed_by_user_id' => $user->id, 'confirmed_at' => now()]);
            Receipt::create([
                'receipt_number' => $receiptNumber,
                'payment_id' => $payment->id,
                'snapshot' => [
                    'payment_reference' => $payment->payment_reference,
                    'member_id' => $payment->member_id,
                    'payer_name' => $payment->payer_name,
                    'received_on' => $payment->received_on->toDateString(),
                    'payment_method' => $payment->payment_method,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'allocations_at_generation' => [],
                    'unallocated_amount' => $payment->amount,
                ],
                'generated_by_user_id' => $user->id,
                'generated_at' => now(),
            ]);
            Audit::record('payment.confirmed', $payment, ['status' => 'pending'], ['status' => 'confirmed', 'amount' => $payment->amount, 'currency' => $payment->currency]);
            Audit::record('receipt.generated', $payment->receipt, [], ['receipt_number' => $receiptNumber, 'payment_id' => $payment->id, 'amount' => $payment->amount]);

            return $payment->fresh()->load('receipt');
        });
    }

    public function preview(User $user, Payment $payment): array
    {
        abort_unless($payment->member_id, 422, 'The payment must be matched to a member.');
        $invoices = \App\Models\Invoice::with('lines')->where('member_id', $payment->member_id)->where('outstanding_amount', '>', 0)->whereNotIn('status', ['draft', 'void'])->get()
            ->filter(fn ($invoice) => app(FinanceAccessService::class)->canViewInvoice($user, $invoice))->values();

        return ['payment' => $payment, 'available_amount' => $payment->unallocated_amount, 'invoices' => $invoices];
    }

    public function allocate(User $user, Payment $payment, \App\Models\Invoice $invoice, int $amount, string $idempotencyKey): PaymentAllocation
    {
        abort_unless(app(FinanceAccessService::class)->canWriteInvoice($user, $invoice), 403);

        return DB::transaction(function () use ($user, $payment, $invoice, $amount, $idempotencyKey) {
            if ($existing = PaymentAllocation::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $invoice = \App\Models\Invoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless(in_array($payment->status, ['confirmed', 'partially_allocated', 'allocated'], true), 422, 'Payment must be confirmed.');
            abort_unless($payment->member_id === $invoice->member_id, 422, 'Cross-member allocation is not permitted.');
            abort_unless($payment->currency === $invoice->currency, 422, 'Payment and invoice currencies must match.');
            abort_unless($amount > 0 && $amount <= $payment->unallocated_amount && $amount <= $invoice->outstanding_amount, 422, 'Allocation exceeds the available balance.');
            $allocation = PaymentAllocation::create([
                'payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $amount,
                'idempotency_key' => $idempotencyKey, 'allocated_by_user_id' => $user->id, 'allocated_at' => now(),
            ]);
            $allocated = (int) $payment->allocations()->whereNull('reversed_at')->sum('amount');
            $unallocated = $payment->amount - $allocated;
            $payment->update(['allocated_amount' => $allocated, 'unallocated_amount' => $unallocated, 'status' => $unallocated === 0 ? 'allocated' : 'partially_allocated']);
            $this->ledger->recordInvoiceOwners($invoice, 'payment_allocation', $amount, "allocation:{$allocation->id}", null, $payment->id);
            $invoice = $this->reconciliation->reconcile($invoice);
            app(BookingService::class)->syncInvoicePayment($invoice);
            Audit::record('payment.allocated', $allocation, [], ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => $amount, 'currency' => $payment->currency]);

            return $allocation->load(['payment', 'invoice']);
        });
    }

    public function reverseAllocation(User $user, PaymentAllocation $allocation, string $reason): PaymentAllocation
    {
        abort_unless(app(FinanceAccessService::class)->canWriteInvoice($user, $allocation->invoice), 403);
        abort_if($allocation->reversed_at, 422, 'Allocation is already reversed.');

        return DB::transaction(function () use ($user, $allocation, $reason) {
            $allocation = PaymentAllocation::lockForUpdate()->findOrFail($allocation->id);
            $payment = Payment::lockForUpdate()->findOrFail($allocation->payment_id);
            $invoice = \App\Models\Invoice::lockForUpdate()->findOrFail($allocation->invoice_id);
            $allocation->update(['reversed_at' => now(), 'reversed_by_user_id' => $user->id, 'reversal_reason' => $reason]);
            $allocated = (int) $payment->allocations()->whereNull('reversed_at')->sum('amount');
            $unallocated = $payment->amount - $allocated;
            $payment->update(['allocated_amount' => $allocated, 'unallocated_amount' => $unallocated, 'status' => $allocated === 0 ? 'confirmed' : ($unallocated === 0 ? 'allocated' : 'partially_allocated')]);
            $this->ledger->recordInvoiceOwners($invoice, 'allocation_reversal', -$allocation->amount, "allocation-reversal:{$allocation->id}", $reason, $payment->id);
            $invoice = $this->reconciliation->reconcile($invoice);
            app(BookingService::class)->syncInvoicePayment($invoice);
            Audit::record('payment.allocation_reversed', $allocation, [], ['amount' => $allocation->amount, 'reason' => $reason]);

            return $allocation->fresh();
        });
    }

    public function reversePayment(User $user, Payment $payment, string $reason): Payment
    {
        abort_unless($user->isCentralAdministrator(), 403);
        abort_unless($payment->status !== 'pending' && ! $payment->reversed_at, 422);
        abort_unless($payment->allocations()->whereNull('reversed_at')->doesntExist(), 422, 'Reverse active allocations first.');
        $payment->update(['status' => 'reversed', 'reversed_by_user_id' => $user->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
        Audit::record('payment.reversed', $payment, [], ['amount' => $payment->amount, 'currency' => $payment->currency, 'reason' => $reason]);

        return $payment->fresh();
    }

    public function refund(User $user, Payment $payment, int $amount, string $reason, string $reference): Payment
    {
        abort_unless($user->isCentralAdministrator(), 403);
        abort_unless($amount > 0 && $amount <= $payment->unallocated_amount, 422, 'Refund exceeds member credit.');
        \App\Models\FinancialTransaction::create([
            'member_id' => $payment->member_id, 'payment_id' => $payment->id, 'financial_owner_type' => 'central_hsc',
            'transaction_type' => 'refund', 'currency' => $payment->currency, 'amount' => -$amount,
            'occurred_on' => now()->toDateString(), 'reference' => $reference, 'reason' => $reason,
            'idempotency_key' => "refund:$reference", 'created_by_user_id' => $user->id,
        ]);
        $payment->decrement('unallocated_amount', $amount);
        $payment->update(['status' => $payment->unallocated_amount === 0 ? 'refunded' : 'partially_allocated']);
        Audit::record('payment.refunded', $payment, [], ['amount' => $amount, 'currency' => $payment->currency, 'reason' => $reason, 'reference' => $reference]);

        return $payment->fresh();
    }
}
