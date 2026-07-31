<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckInRequest;
use App\Models\Booking;
use App\Services\BookingAccessService;
use App\Services\BookingCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function show(Request $r, Booking $booking, BookingCheckInService $s): JsonResponse
    {
        $own = $r->user()->member_id === $booking->member_id;
        abort_unless($own || app(BookingAccessService::class)->canView($r->user(), $booking->facility), 403);

        return response()->json(['attendance' => $s->ensure($booking), 'access_grants' => $booking->accessGrants()->with('zone:id,name,code')->get()]);
    }

    public function issue(Request $r, Booking $booking, BookingCheckInService $s): JsonResponse
    {
        abort_unless($r->user()->member_id === $booking->member_id || app(BookingAccessService::class)->canManage($r->user(), $booking->facility), 403);
        $data = $r->validate(['mode' => ['required', 'in:booking_qr,short_code']]);

        return response()->json($s->issueToken($booking, $data['mode']), 201);
    }

    public function checkIn(CheckInRequest $r, Booking $booking, BookingCheckInService $s): JsonResponse
    {
        $data = $r->validated();

        return response()->json($s->checkIn($r->user(), $booking, $data['method'] ?? 'member', $data['token'] ?? null, $data['reason'] ?? null));
    }
}
