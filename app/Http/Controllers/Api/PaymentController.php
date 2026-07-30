<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AllocatePaymentRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $payments = Payment::with(['member:id,member_number,first_name,last_name', 'allocations.invoice:id,invoice_number', 'receipt'])
            ->latest('received_on')->get();
        if (! $user->isCentralAdministrator()) {
            $allowed = $user->sportCodes()->pluck('sport_codes.id');
            $payments = $payments->filter(fn ($payment) => $payment->allocations->contains(fn ($allocation) => $allocation->invoice->lines()->whereIn('sport_code_id', $allowed)->exists()))->values();
        }

        return response()->json($payments);
    }

    public function store(StorePaymentRequest $request, PaymentService $service): JsonResponse
    {
        return response()->json($service->record($request->user(), $request->validated()), 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator() || $payment->allocations()->whereHas('invoice.lines', fn ($q) => $q->whereIn('sport_code_id', $request->user()->sportCodes()->pluck('sport_codes.id')))->exists(), 403);

        return response()->json($payment->load(['member', 'allocations.invoice.lines', 'receipt']));
    }

    public function confirm(Request $request, Payment $payment, PaymentService $service): JsonResponse
    {
        return response()->json($service->confirm($request->user(), $payment));
    }

    public function preview(Request $request, Payment $payment, PaymentService $service): JsonResponse
    {
        return response()->json($service->preview($request->user(), $payment));
    }

    public function allocate(AllocatePaymentRequest $request, Payment $payment, PaymentService $service): JsonResponse
    {
        $data = $request->validated();

        return response()->json($service->allocate($request->user(), $payment, Invoice::findOrFail($data['invoice_id']), $data['amount'], $data['idempotency_key']), 201);
    }

    public function reverseAllocation(Request $request, PaymentAllocation $allocation, PaymentService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json($service->reverseAllocation($request->user(), $allocation, $data['reason']));
    }

    public function reverse(Request $request, Payment $payment, PaymentService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json($service->reversePayment($request->user(), $payment, $data['reason']));
    }

    public function refund(Request $request, Payment $payment, PaymentService $service): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string'], 'reference' => ['required', 'string', 'max:100']]);

        return response()->json($service->refund($request->user(), $payment, $data['amount'], $data['reason'], $data['reference']));
    }
}
