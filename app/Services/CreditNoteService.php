<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\CreditNoteAllocation;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private InvoiceReconciliationService $reconciliation,
        private FinancialLedgerService $ledger,
    ) {}

    public function create(User $user, Invoice $invoice, array $lines, string $reason): CreditNote
    {
        abort_unless(app(FinanceAccessService::class)->canWriteInvoice($user, $invoice), 403);
        abort_unless(! in_array($invoice->status, ['draft', 'void'], true), 422);
        $total = collect($lines)->sum('amount');
        abort_unless($total > 0 && $total <= $invoice->total_amount, 422, 'Credit amount is invalid.');
        foreach ($lines as $line) {
            abort_unless($line['amount'] > 0, 422);
            if ($line['financial_owner_type'] === 'central_hsc') {
                abort_unless($user->isCentralAdministrator(), 403, 'Only central HSC may credit levy-owned charges.');
            }
        }

        return DB::transaction(function () use ($invoice, $lines, $reason, $total) {
            $note = CreditNote::create([
                'invoice_id' => $invoice->id, 'member_id' => $invoice->member_id, 'currency' => $invoice->currency,
                'status' => 'draft', 'total_amount' => $total, 'applied_amount' => 0, 'reason' => $reason,
            ]);
            foreach ($lines as $line) {
                $note->lines()->create($line);
            }
            Audit::record('credit_note.created', $note, [], ['invoice_id' => $invoice->id, 'amount' => $total, 'currency' => $invoice->currency, 'reason' => $reason]);

            return $note->load('lines');
        });
    }

    public function issue(User $user, CreditNote $note): CreditNote
    {
        abort_unless(app(FinanceAccessService::class)->canWriteInvoice($user, $note->invoice), 403);
        abort_unless($note->status === 'draft', 422);
        $number = $this->numbers->next('credit_note');
        $note->update(['credit_note_number' => $number, 'status' => 'issued', 'issued_on' => now()->toDateString(), 'issued_by_user_id' => $user->id]);
        Audit::record('credit_note.issued', $note, ['status' => 'draft'], ['status' => 'issued', 'credit_note_number' => $number, 'amount' => $note->total_amount]);

        return $note->fresh()->load('lines');
    }

    public function apply(User $user, CreditNote $note, Invoice $invoice, int $amount, string $idempotencyKey): CreditNoteAllocation
    {
        abort_unless(app(FinanceAccessService::class)->canWriteInvoice($user, $invoice), 403);

        return DB::transaction(function () use ($user, $note, $invoice, $amount, $idempotencyKey) {
            if ($existing = CreditNoteAllocation::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }
            $note = CreditNote::lockForUpdate()->findOrFail($note->id);
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            abort_unless($note->status === 'issued' || $note->status === 'partially_applied', 422);
            abort_unless($note->member_id === $invoice->member_id && $note->currency === $invoice->currency, 422);
            $available = $note->total_amount - $note->applied_amount;
            abort_unless($amount > 0 && $amount <= $available && $amount <= $invoice->outstanding_amount, 422);
            $allocation = CreditNoteAllocation::create([
                'credit_note_id' => $note->id, 'invoice_id' => $invoice->id, 'amount' => $amount,
                'idempotency_key' => $idempotencyKey, 'applied_by_user_id' => $user->id, 'applied_at' => now(),
            ]);
            $applied = (int) $note->allocations()->whereNull('reversed_at')->sum('amount');
            $note->update(['applied_amount' => $applied, 'status' => $applied === $note->total_amount ? 'applied' : 'partially_applied']);
            $this->ledger->recordInvoiceOwners($invoice, 'credit_note', $amount, "credit-allocation:{$allocation->id}", $note->reason, null, $note->id);
            $this->reconciliation->reconcile($invoice);
            Audit::record('credit_note.applied', $note, [], ['invoice_id' => $invoice->id, 'amount' => $amount, 'currency' => $note->currency]);

            return $allocation;
        });
    }
}
