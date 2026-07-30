<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\MembershipPeriod;
use App\Models\SportCode;
use App\Models\SportMembership;
use App\Models\User;
use App\Services\CreditNoteService;
use App\Services\DocumentNumberService;
use App\Services\FinancialReportService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_numbers_are_unique_and_sequences_are_not_derived_from_documents(): void
    {
        $service = app(DocumentNumberService::class);
        $first = $service->next('invoice');
        $second = $service->next('invoice');
        $receipt = $service->next('receipt');

        $this->assertSame('INV-0000001', $first);
        $this->assertSame('INV-0000002', $second);
        $this->assertSame('RCT-0000001', $receipt);
        $this->assertDatabaseHas('document_sequences', ['document_type' => 'invoice', 'next_value' => 3]);
    }

    public function test_membership_invoice_uses_frozen_snapshot_and_is_idempotent(): void
    {
        [$admin, $membership, $category] = $this->approvedMembership();
        $this->actingAs($admin);
        $first = app(InvoiceService::class)->generateMembershipInvoice($admin, $membership);
        $category->update(['code_fee_cents' => 999999]);
        $second = app(InvoiceService::class)->generateMembershipInvoice($admin, $membership);

        $this->assertSame('created', $first['outcome']);
        $this->assertSame('already_invoiced', $second['outcome']);
        $invoice = $first['invoice'];
        $this->assertSame(125000, $invoice->total_amount);
        $this->assertSame([100000, 20000, 10000, -5000], $invoice->lines->pluck('total_amount')->all());
        $this->assertSame(['sport_code', 'central_hsc', 'sport_code', 'sport_code'], $invoice->lines->pluck('financial_owner_type')->all());
        $this->assertSame(1, Invoice::count());
    }

    public function test_issued_invoice_is_immutable_and_void_requires_an_unpaid_invoice(): void
    {
        [$admin, $membership] = $this->approvedMembership();
        $this->actingAs($admin);
        $invoice = app(InvoiceService::class)->generateMembershipInvoice($admin, $membership)['invoice'];
        $issued = app(InvoiceService::class)->issue($admin, $invoice, '2027-01-15');

        $this->assertSame('INV-0000001', $issued->invoice_number);
        $this->assertSame('2027-02-14', $issued->due_on->toDateString());
        $this->expectException(\LogicException::class);
        $issued->lines->first()->update(['description' => 'Changed']);
    }

    public function test_partial_multiple_and_cross_invoice_allocations_reconcile_and_reverse(): void
    {
        [$admin, $membership] = $this->approvedMembership();
        $this->actingAs($admin);
        $invoice = $this->issued($admin, $membership);
        $second = $this->manualIssuedInvoice($admin, $membership->member_id, $membership->sport_code_id, 50000);
        $payment = app(PaymentService::class)->record($admin, $this->paymentData($membership->member_id, 150000));
        $payment = app(PaymentService::class)->confirm($admin, $payment);
        $allocation = app(PaymentService::class)->allocate($admin, $payment, $invoice, 75000, 'allocation-one');
        app(PaymentService::class)->allocate($admin, $payment, $second, 50000, 'allocation-two');

        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame(50000, $invoice->fresh()->outstanding_amount);
        $this->assertSame('paid', $second->fresh()->status);
        $this->assertSame(25000, $payment->fresh()->unallocated_amount);
        $this->assertSame($allocation->id, app(PaymentService::class)->allocate($admin, $payment, $invoice->fresh(), 75000, 'allocation-one')->id);

        app(PaymentService::class)->reverseAllocation($admin, $allocation, 'Correction');
        $this->assertSame(125000, $invoice->fresh()->outstanding_amount);
        $this->assertSame(100000, $payment->fresh()->unallocated_amount);
        $this->assertSame(125000, app(FinancialReportService::class)->statement($admin, $membership->member_id)['closing_balance']);
    }

    public function test_overpayment_credit_can_be_applied_later_and_receipt_is_preserved(): void
    {
        [$admin, $membership] = $this->approvedMembership();
        $this->actingAs($admin);
        $invoice = $this->issued($admin, $membership);
        $payment = app(PaymentService::class)->confirm($admin, app(PaymentService::class)->record($admin, $this->paymentData($membership->member_id, 175000)));
        app(PaymentService::class)->allocate($admin, $payment, $invoice, 125000, 'settle-first');
        $second = $this->manualIssuedInvoice($admin, $membership->member_id, $membership->sport_code_id, 50000);
        app(PaymentService::class)->allocate($admin, $payment->fresh(), $second, 50000, 'apply-credit');

        $this->assertSame(0, $payment->fresh()->unallocated_amount);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('paid', $second->fresh()->status);
        $this->assertNotNull($payment->receipt);
        $this->assertSame(175000, $payment->receipt->snapshot['unallocated_amount']);
    }

    public function test_credit_note_reduces_balance_without_changing_original_invoice(): void
    {
        [$admin, $membership] = $this->approvedMembership();
        $this->actingAs($admin);
        $invoice = $this->issued($admin, $membership);
        $line = $invoice->lines->where('line_type', 'membership_fee')->first();
        $note = app(CreditNoteService::class)->create($admin, $invoice, [[
            'invoice_line_id' => $line->id, 'financial_owner_type' => 'sport_code',
            'sport_code_id' => $membership->sport_code_id, 'description' => 'Partial fee correction', 'amount' => 25000,
        ]], 'Approved correction');
        $note = app(CreditNoteService::class)->issue($admin, $note);
        app(CreditNoteService::class)->apply($admin, $note, $invoice, 25000, 'credit-one');

        $this->assertSame(125000, $invoice->fresh()->total_amount);
        $this->assertSame(100000, $invoice->fresh()->outstanding_amount);
        $this->assertSame('CN-0000001', $note->credit_note_number);
        $this->assertSame('applied', $note->fresh()->status);
    }

    public function test_sport_and_levy_reports_separate_financial_owners(): void
    {
        [$admin, $membership] = $this->approvedMembership();
        $this->actingAs($admin);
        $invoice = $this->issued($admin, $membership);
        $payment = app(PaymentService::class)->confirm($admin, app(PaymentService::class)->record($admin, $this->paymentData($membership->member_id, 125000)));
        app(PaymentService::class)->allocate($admin, $payment, $invoice, 125000, 'report-allocation');
        $sport = app(FinancialReportService::class)->sportCode($admin, $membership->sport_code_id);
        $levy = app(FinancialReportService::class)->levy($admin);

        $this->assertSame(105000, $sport['invoiced']);
        $this->assertSame(20000, $levy['invoiced']);
        $this->assertSame(20000, $levy['collected']);
        $this->assertSame(0, $levy['outstanding']);
    }

    public function test_financial_authorization_blocks_cross_code_read_only_and_disabled_users(): void
    {
        [$admin, $membership] = $this->approvedMembership();
        $this->actingAs($admin);
        $invoice = $this->issued($admin, $membership);
        $padel = SportCode::create(['name' => 'Padel', 'slug' => 'padel']);
        $cross = User::factory()->create(['role' => 'sport_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'cross')]);
        $cross->sportCodes()->attach($padel->id, ['role' => 'administrator']);
        $viewer = User::factory()->create(['role' => 'sport_viewer', 'active' => true, 'api_token_hash' => hash('sha256', 'viewer')]);
        $viewer->sportCodes()->attach($membership->sport_code_id, ['role' => 'viewer']);
        $disabled = User::factory()->create(['role' => 'hsc_admin', 'active' => false, 'api_token_hash' => hash('sha256', 'disabled')]);

        $this->withToken('cross')->getJson("/api/finance/invoices/{$invoice->id}")->assertForbidden();
        $this->withToken('viewer')->postJson("/api/finance/invoices/{$invoice->id}/action", ['action' => 'void', 'reason' => 'No'])->assertForbidden();
        $this->withToken('disabled')->getJson('/api/finance/invoices')->assertUnauthorized();
        $this->getJson('/api/finance/invoices')->assertUnauthorized();
    }

    private function approvedMembership(): array
    {
        $admin = User::factory()->create(['role' => 'hsc_super_admin', 'active' => true, 'api_token_hash' => hash('sha256', 'admin')]);
        $code = SportCode::create(['name' => 'Tennis', 'slug' => 'tennis']);
        $category = MembershipCategory::create(['sport_code_id' => $code->id, 'name' => 'Full', 'billing_period' => 'annual', 'code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'active' => true]);
        $period = MembershipPeriod::factory()->create(['status' => 'open']);
        $member = Member::create(['member_number' => 'HSC-FIN1', 'first_name' => 'Finance', 'last_name' => 'Member', 'date_of_birth' => '1980-01-01', 'status' => 'active']);
        $application = MembershipApplication::create([
            'member_id' => $member->id, 'sport_code_id' => $code->id, 'membership_category_id' => $category->id,
            'membership_period_id' => $period->id, 'application_type' => 'new_membership', 'status' => 'approved',
            'sport_code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'joining_fee_cents' => 10000,
            'discount_cents' => 5000, 'total_payable_cents' => 125000, 'billing_frequency' => 'annual', 'currency' => 'ZAR',
        ]);
        $membership = SportMembership::create([
            'member_id' => $member->id, 'sport_code_id' => $code->id, 'membership_category_id' => $category->id,
            'membership_period_id' => $period->id, 'source_application_id' => $application->id, 'status' => 'active',
            'role' => 'member', 'joined_or_renewed_on' => '2027-01-01', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
            'sport_code_fee_cents' => 100000, 'hsc_levy_cents' => 20000, 'total_cents' => 125000, 'billing_frequency' => 'annual',
        ]);

        return [$admin, $membership, $category];
    }

    private function issued(User $admin, SportMembership $membership): Invoice
    {
        $invoice = app(InvoiceService::class)->generateMembershipInvoice($admin, $membership)['invoice'];

        return app(InvoiceService::class)->issue($admin, $invoice);
    }

    private function manualIssuedInvoice(User $admin, int $memberId, int $sportCodeId, int $amount): Invoice
    {
        $invoice = Invoice::create(['member_id' => $memberId, 'currency' => 'ZAR', 'status' => 'draft', 'subtotal_amount' => $amount, 'total_amount' => $amount, 'outstanding_amount' => $amount, 'billing_event' => 'manual']);
        $invoice->lines()->create(['line_type' => 'other', 'financial_owner_type' => 'sport_code', 'sport_code_id' => $sportCodeId, 'description' => 'Other charge', 'quantity' => 1, 'unit_amount' => $amount, 'gross_amount' => $amount, 'net_amount' => $amount, 'total_amount' => $amount]);

        return app(InvoiceService::class)->issue($admin, $invoice);
    }

    private function paymentData(int $memberId, int $amount): array
    {
        return ['payment_reference' => 'PAY-'.str()->random(10), 'member_id' => $memberId, 'payer_name' => 'Finance Member', 'currency' => 'ZAR', 'amount' => $amount, 'received_on' => '2027-01-20', 'payment_method' => 'eft', 'external_reference' => null, 'bank_reference' => 'FIN1', 'import_fingerprint' => null, 'source' => 'manual'];
    }
}
