<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\SportMembership;
use App\Services\FinanceAccessService;
use App\Services\InvoiceService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();
        $sportIds = $user->isCentralAdministrator()
            ? \App\Models\SportCode::pluck('id')
            : $user->sportCodes()->pluck('sport_codes.id');
        $memberships = SportMembership::with(['member:id,member_number,first_name,last_name', 'sportCode:id,name', 'period:id,name'])
            ->whereIn('sport_code_id', $sportIds)
            ->whereHas('sourceApplication', fn ($q) => $q->where('status', 'approved'))
            ->get();

        return response()->json([
            'memberships' => $memberships,
            'members' => \App\Models\Member::orderBy('last_name')->get(['id', 'member_number', 'first_name', 'last_name']),
            'sport_codes' => \App\Models\SportCode::whereIn('id', $sportIds)->get(['id', 'name']),
            'periods' => \App\Models\MembershipPeriod::latest('starts_on')->get(['id', 'name']),
            'can_manage_settings' => $user->isCentralAdministrator(),
            'can_write' => $user->isCentralAdministrator() || $user->sportCodes()->whereIn('sport_code_user.role', ['administrator', 'committee'])->exists(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowed = $user->sportCodes()->pluck('sport_codes.id');
        $query = Invoice::with(['member:id,member_number,first_name,last_name', 'lines', 'period:id,name'])
            ->when(! $user->isCentralAdministrator(), fn ($q) => $q
                ->whereHas('lines', fn ($line) => $line->whereIn('sport_code_id', $allowed))
                ->whereDoesntHave('lines', fn ($line) => $line->whereNotNull('sport_code_id')->whereNotIn('sport_code_id', $allowed)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('member_id'), fn ($q) => $q->where('member_id', $request->integer('member_id')))
            ->when($request->filled('invoice_number'), fn ($q) => $q->where('invoice_number', 'like', '%'.$request->string('invoice_number').'%'))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('due_on', '<', now()->toDateString())->where('outstanding_amount', '>', 0))
            ->latest();

        return response()->json($query->paginate(50));
    }

    public function show(Request $request, Invoice $invoice, FinanceAccessService $access): JsonResponse
    {
        abort_unless($access->canViewInvoice($request->user(), $invoice), 403);

        return response()->json($invoice->load(['member', 'period', 'lines.sportCode', 'allocations.payment', 'creditNotes.lines', 'transactions']));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        $data = $request->validate([
            'member_id' => ['required', 'exists:members,id'],
            'membership_period_id' => ['nullable', 'exists:membership_periods,id'],
            'currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_type' => ['required', Rule::in(['facility', 'event', 'other', 'adjustment'])],
            'lines.*.financial_owner_type' => ['required', Rule::in(['sport_code', 'central_hsc', 'facility', 'other'])],
            'lines.*.sport_code_id' => ['nullable', 'exists:sport_codes,id'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_amount' => ['required', 'integer', 'min:0'],
        ]);
        $invoice = DB::transaction(function () use ($data) {
            $invoice = Invoice::create([...collect($data)->except('lines')->all(), 'status' => 'draft', 'source_type' => null, 'billing_event' => 'manual']);
            foreach ($data['lines'] as $line) {
                $gross = $line['quantity'] * $line['unit_amount'];
                $invoice->lines()->create([...$line, 'gross_amount' => $gross, 'discount_amount' => 0, 'net_amount' => $gross, 'tax_amount' => 0, 'total_amount' => $gross]);
            }
            $total = (int) $invoice->lines()->sum('total_amount');
            $invoice->update(['subtotal_amount' => $total, 'total_amount' => $total, 'outstanding_amount' => $total]);
            Audit::record('invoice.draft_created', $invoice, [], ['member_id' => $invoice->member_id, 'amount' => $total, 'currency' => $invoice->currency]);

            return $invoice;
        });

        return response()->json($invoice->load('lines'), 201);
    }

    public function update(Request $request, Invoice $invoice, FinanceAccessService $access): JsonResponse
    {
        abort_unless($access->canWriteInvoice($request->user(), $invoice), 403);
        abort_unless($invoice->status === 'draft', 422, 'Issued invoices are immutable.');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $old = $invoice->only('notes');
        $invoice->update($data);
        Audit::record('invoice.draft_updated', $invoice, $old, $data);

        return response()->json($invoice->fresh());
    }

    public function generate(Request $request, SportMembership $sportMembership, InvoiceService $service): JsonResponse
    {
        return response()->json($service->generateMembershipInvoice($request->user(), $sportMembership));
    }

    public function bulk(Request $request, InvoiceService $service): JsonResponse
    {
        $data = $request->validate(['sport_code_id' => ['nullable', 'exists:sport_codes,id']]);

        return response()->json(['results' => $service->bulkMembershipInvoices($request->user(), $data['sport_code_id'] ?? null)]);
    }

    public function action(Request $request, Invoice $invoice, InvoiceService $service): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['issue', 'void', 'write_off', 'reconcile'])],
            'issued_on' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);
        $result = match ($data['action']) {
            'issue' => $service->issue($request->user(), $invoice, $data['issued_on'] ?? null),
            'void' => $service->void($request->user(), $invoice, $data['reason'] ?? abort(422, 'A reason is required.')),
            'write_off' => $service->writeOff($request->user(), $invoice, (int) ($data['amount'] ?? 0), $data['reason'] ?? abort(422, 'A reason is required.')),
            'reconcile' => app(\App\Services\InvoiceReconciliationService::class)->reconcile($invoice),
        };

        return response()->json($result);
    }
}
