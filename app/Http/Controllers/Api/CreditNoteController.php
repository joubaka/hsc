<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\CreditNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditNoteController extends Controller
{
    public function store(Request $request, Invoice $invoice, CreditNoteService $service): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_line_id' => ['nullable', 'exists:invoice_lines,id'],
            'lines.*.financial_owner_type' => ['required', Rule::in(['sport_code', 'central_hsc', 'facility', 'other'])],
            'lines.*.sport_code_id' => ['nullable', 'exists:sport_codes,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($service->create($request->user(), $invoice, $data['lines'], $data['reason']), 201);
    }

    public function issue(Request $request, CreditNote $creditNote, CreditNoteService $service): JsonResponse
    {
        return response()->json($service->issue($request->user(), $creditNote));
    }

    public function apply(Request $request, CreditNote $creditNote, CreditNoteService $service): JsonResponse
    {
        $data = $request->validate(['invoice_id' => ['required', 'exists:invoices,id'], 'amount' => ['required', 'integer', 'min:1'], 'idempotency_key' => ['required', 'string', 'max:100']]);

        return response()->json($service->apply($request->user(), $creditNote, Invoice::findOrFail($data['invoice_id']), $data['amount'], $data['idempotency_key']), 201);
    }
}
