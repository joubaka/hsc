<?php

namespace App\Services;

use App\Models\FinancialSetting;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\SportMembership;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private DocumentNumberService $numbers,
        private InvoiceReconciliationService $reconciliation,
        private FinancialLedgerService $ledger,
    ) {}

    public function generateMembershipInvoice(User $user, SportMembership $membership): array
    {
        abort_unless($user->canAccessSportCode($membership->sport_code_id, true), 403);

        return DB::transaction(function () use ($membership) {
            $membership = SportMembership::with(['member', 'sportCode', 'category', 'sourceApplication', 'period'])
                ->lockForUpdate()->findOrFail($membership->id);
            abort_unless($membership->sourceApplication?->status === 'approved', 422, 'Only approved memberships may be invoiced.');
            if ($existing = Invoice::where(['source_type' => SportMembership::class, 'source_id' => $membership->id, 'billing_event' => 'membership'])->first()) {
                return ['outcome' => 'already_invoiced', 'invoice' => $existing->load('lines')];
            }
            abort_if($membership->sport_code_fee_cents === null || $membership->hsc_levy_cents === null || $membership->total_cents === null, 422, 'The membership fee snapshot is missing.');
            $settings = FinancialSetting::firstOrCreate([])->fresh();
            if ($membership->total_cents === 0 && ! $settings->generate_zero_value_invoices) {
                return ['outcome' => 'zero_value', 'invoice' => null];
            }
            $application = $membership->sourceApplication;
            $invoice = Invoice::create([
                'member_id' => $membership->member_id,
                'membership_period_id' => $membership->membership_period_id,
                'currency' => $application->currency ?: $settings->default_currency,
                'status' => 'draft',
                'subtotal_amount' => $membership->sport_code_fee_cents + $membership->hsc_levy_cents + $application->joining_fee_cents,
                'discount_amount' => $application->discount_cents,
                'total_amount' => $membership->total_cents,
                'outstanding_amount' => $membership->total_cents,
                'source_type' => SportMembership::class,
                'source_id' => $membership->id,
                'billing_event' => 'membership',
            ]);
            $metadata = ['membership_category_id' => $membership->membership_category_id, 'application_id' => $application->id, 'period_id' => $membership->membership_period_id];
            if ($membership->sport_code_fee_cents > 0) {
                $invoice->lines()->create($this->line('membership_fee', 'sport_code', $membership->sport_code_id, "{$membership->sportCode->name} {$membership->category->name} membership", $membership->sport_code_fee_cents, $membership, $metadata));
            }
            if ($membership->hsc_levy_cents > 0) {
                $invoice->lines()->create($this->line('hsc_levy', 'central_hsc', null, 'Hermanus Sports Club levy', $membership->hsc_levy_cents, $membership, $metadata));
            }
            if ($application->joining_fee_cents > 0) {
                $owner = $settings->joining_fee_owner_type;
                $invoice->lines()->create($this->line('joining_fee', $owner, $owner === 'sport_code' ? $membership->sport_code_id : null, 'Joining fee', $application->joining_fee_cents, $application, $metadata));
            }
            if ($application->discount_cents > 0) {
                $invoice->lines()->create($this->line('discount', 'sport_code', $membership->sport_code_id, 'Approved membership discount', -$application->discount_cents, $application, $metadata));
            }
            abort_unless((int) $invoice->lines()->sum('total_amount') === (int) $invoice->total_amount, 500, 'Invoice line reconciliation failed.');
            Audit::record('invoice.membership_generated', $invoice, [], ['member_id' => $invoice->member_id, 'sport_code_id' => $membership->sport_code_id, 'amount' => $invoice->total_amount, 'currency' => $invoice->currency]);

            return ['outcome' => 'created', 'invoice' => $invoice->load('lines')];
        });
    }

    public function bulkMembershipInvoices(User $user, ?int $sportCodeId = null): array
    {
        $memberships = SportMembership::with('sourceApplication')->whereHas('sourceApplication', fn ($q) => $q->where('status', 'approved'))
            ->when($sportCodeId, fn ($q) => $q->where('sport_code_id', $sportCodeId))
            ->when(! $user->isCentralAdministrator(), fn ($q) => $q->whereIn('sport_code_id', $user->sportCodes()->whereIn('sport_code_user.role', ['administrator', 'committee'])->pluck('sport_codes.id')))
            ->get();

        return $memberships->map(function ($membership) use ($user) {
            try {
                $result = $this->generateMembershipInvoice($user, $membership);

                return ['membership_id' => $membership->id, 'member_id' => $membership->member_id, 'outcome' => $result['outcome'], 'invoice_id' => $result['invoice']?->id];
            } catch (\Throwable $exception) {
                report($exception);

                return ['membership_id' => $membership->id, 'member_id' => $membership->member_id, 'outcome' => 'failed'];
            }
        })->all();
    }

    public function issue(User $user, Invoice $invoice, ?string $issuedOn = null): Invoice
    {
        abort_unless(app(FinanceAccessService::class)->canWriteInvoice($user, $invoice), 403);
        abort_unless($invoice->status === 'draft', 422, 'Only draft invoices may be issued.');
        $number = $this->numbers->next('invoice');

        return DB::transaction(function () use ($invoice, $issuedOn, $number, $user) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $settings = FinancialSetting::firstOrCreate([])->fresh();
            $issueDate = Carbon::parse($issuedOn ?: now()->toDateString())->startOfDay();
            $invoice->update([
                'invoice_number' => $number,
                'status' => 'issued',
                'issued_on' => $issueDate,
                'due_on' => $issueDate->copy()->addDays($settings->default_invoice_due_days),
                'issued_by_user_id' => $user->id,
            ]);
            foreach ($invoice->lines as $line) {
                FinancialTransaction::create([
                    'member_id' => $invoice->member_id, 'invoice_id' => $invoice->id,
                    'membership_period_id' => $invoice->membership_period_id, 'sport_code_id' => $line->sport_code_id,
                    'financial_owner_type' => $line->financial_owner_type, 'transaction_type' => 'invoice_charge',
                    'currency' => $invoice->currency, 'amount' => $line->total_amount, 'occurred_on' => $issueDate,
                    'reference' => $number, 'idempotency_key' => "invoice:{$invoice->id}:line:{$line->id}", 'created_by_user_id' => $user->id,
                ]);
            }
            Audit::record('invoice.issued', $invoice, ['status' => 'draft'], ['status' => 'issued', 'invoice_number' => $number, 'amount' => $invoice->total_amount]);

            return $this->reconciliation->reconcile($invoice)->load('lines');
        });
    }

    public function void(User $user, Invoice $invoice, string $reason): Invoice
    {
        abort_unless(app(FinanceAccessService::class)->canWriteInvoice($user, $invoice), 403);
        abort_unless($invoice->status === 'issued' && $invoice->paid_amount === 0 && $invoice->credited_amount === 0, 422, 'Only unpaid, uncredited issued invoices may be voided.');
        $invoice->update(['status' => 'void', 'voided_by_user_id' => $user->id, 'voided_at' => now(), 'void_reason' => $reason, 'outstanding_amount' => 0]);
        $this->ledger->recordInvoiceOwners($invoice, 'invoice_void', $invoice->total_amount, "invoice-void:{$invoice->id}", $reason);
        Audit::record('invoice.voided', $invoice, ['status' => 'issued'], ['status' => 'void', 'reason' => $reason]);

        return $invoice->fresh();
    }

    public function writeOff(User $user, Invoice $invoice, int $amount, string $reason): Invoice
    {
        abort_unless($user->isCentralAdministrator(), 403);
        abort_unless($amount > 0 && $amount <= $invoice->outstanding_amount && ! in_array($invoice->status, ['draft', 'void'], true), 422);
        $this->ledger->recordInvoiceOwners($invoice, 'write_off', $amount, 'write-off:'.str()->uuid(), $reason);
        Audit::record('invoice.written_off', $invoice, [], ['amount' => $amount, 'currency' => $invoice->currency, 'reason' => $reason]);

        return $this->reconciliation->reconcile($invoice);
    }

    private function line(string $type, string $owner, ?int $sportCodeId, string $description, int $amount, $source, array $metadata): array
    {
        return [
            'line_type' => $type, 'financial_owner_type' => $owner, 'sport_code_id' => $sportCodeId,
            'description' => $description, 'quantity' => 1, 'unit_amount' => $amount,
            'gross_amount' => $amount, 'discount_amount' => $type === 'discount' ? abs($amount) : 0,
            'net_amount' => $amount, 'tax_amount' => 0, 'total_amount' => $amount,
            'source_type' => $source::class, 'source_id' => $source->id, 'snapshot_metadata' => $metadata,
        ];
    }
}
