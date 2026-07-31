<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingRefundRequest;
use App\Services\BookingFinancialCompensationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingRefundController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);

        return response()->json(
            BookingRefundRequest::with([
                'booking:id,booking_reference,facility_id,cancelled_at',
                'booking.facility:id,name,sport_code_id',
                'invoice:id,invoice_number,total_amount,paid_amount',
                'payment:id,payment_reference,amount',
                'compensation:id,booking_id,policy_band,compensation_percentage,financial_owner_breakdown,reason',
            ])->latest()->paginate(50),
        );
    }

    public function complete(Request $request, BookingRefundRequest $refundRequest, BookingFinancialCompensationService $service): JsonResponse
    {
        $data = $request->validate(['external_reference' => ['required', 'string', 'max:120']]);

        return response()->json($service->completeRefund($request->user(), $refundRequest, $data['external_reference']));
    }
}
