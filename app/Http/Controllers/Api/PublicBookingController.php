<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingHold;
use App\Models\Facility;
use App\Models\PublicBookingSetting;
use App\Models\PublicVisitor;
use App\Services\PublicBookingService;
use App\Services\PayFastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicBookingController extends Controller
{
    public function identity(Request $r, PublicBookingService $s): JsonResponse
    {
        $d = $r->validate(['first_name' => 'required|string|max:80', 'last_name' => 'required|string|max:80', 'email' => 'required|email|max:190', 'mobile' => 'required|string|max:30', 'preferred_language' => 'nullable|string|max:10', 'marketing_consent' => 'nullable|boolean']);

        return response()->json($s->identify($d, $r));
    }

    public function requestVerification(Request $r, PublicBookingService $s): JsonResponse
    {
        $v = $s->visitor($r);
        $d = $r->validate(['channel' => ['required', Rule::in(['email', 'mobile'])], 'purpose' => 'nullable|string|max:50', 'idempotency_key' => 'required|uuid']);

        return response()->json($s->requestVerification($v, $d, $r), 202);
    }

    public function verify(Request $r, PublicBookingService $s): JsonResponse
    {
        $v = $s->visitor($r);
        $d = $r->validate(['channel' => ['required', Rule::in(['email', 'mobile'])], 'code' => 'required|string|min:6|max:64']);

        return response()->json($s->verify($v, $d, $r));
    }

    public function preview(Request $r, Facility $facility, PublicBookingService $s): JsonResponse
    {
        $v = $s->visitor($r);

        return response()->json($s->preview($v, $facility, $this->bookingData($r)));
    }

    public function hold(Request $r, Facility $facility, PublicBookingService $s): JsonResponse
    {
        $v = $s->visitor($r);
        $d = $this->bookingData($r) + $r->validate(['idempotency_key' => 'required|uuid', 'accept_terms' => 'accepted', 'accept_privacy' => 'accepted', 'accept_facility_rules' => 'accepted']);

        return response()->json($s->hold($v, $facility, $d, $r), 201);
    }

    public function pay(Request $r, BookingHold $hold, PublicBookingService $s): JsonResponse
    {
        $v = $s->visitor($r);
        $d = $r->validate(['outcome' => ['nullable', Rule::in(['success', 'failure'])]]);

        return response()->json($s->pay($v, $hold, $d, $r));
    }

    public function checkoutStatus(Request $r, BookingHold $hold, PublicBookingService $s): JsonResponse
    {
        return response()->json($s->checkoutStatus($s->visitor($r), $hold));
    }

    public function payFastNotify(Request $r, PayFastService $payFast, PublicBookingService $s): JsonResponse
    {
        $s->completePayFastCheckout($payFast->verify($r->all()), $r);
        return response()->json(['status' => 'ok']);
    }

    public function confirm(Request $r, BookingHold $hold, PublicBookingService $s): JsonResponse
    {
        $v = $s->visitor($r);
        $d = $r->validate(['idempotency_key' => 'required|uuid', 'participants' => 'array|max:8', 'participants.*.name' => 'required|string|max:100', 'participants.*.age_group' => ['nullable', Rule::in(['adult', 'junior'])], 'participants.*.role' => 'nullable|string|max:40', 'participants.*.guardian_consent' => 'nullable|boolean']);

        return response()->json($s->confirm($v, $hold, $d, $r), 201);
    }

    public function manage(string $token, PublicBookingService $s): JsonResponse
    {
        return response()->json($s->managed($token));
    }

    public function rotate(string $token, PublicBookingService $s): JsonResponse
    {
        return response()->json($s->rotate($token));
    }

    public function cancel(Request $r, string $token, PublicBookingService $s): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:500']);

        return response()->json($s->cancel($token, $d['reason']));
    }

    public function participants(Request $r, string $token, PublicBookingService $s): JsonResponse
    {
        $d = $r->validate(['participants' => 'array|max:8', 'participants.*.name' => 'required|string|max:100', 'participants.*.age_group' => ['nullable', Rule::in(['adult', 'junior'])], 'participants.*.role' => 'nullable|string|max:40', 'participants.*.guardian_consent' => 'nullable|boolean']);

        return response()->json($s->updateParticipants($token, $d['participants'] ?? [], $r));
    }

    public function checkIn(Request $r, string $token, PublicBookingService $s): JsonResponse
    {
        return response()->json($s->checkIn($token, $r));
    }

    public function admin(Request $r, PublicBookingService $s): JsonResponse
    {
        return response()->json($s->administration($r->user()));
    }

    public function visitorStatus(Request $r, PublicVisitor $visitor, PublicBookingService $s): JsonResponse
    {
        $d = $r->validate(['status' => ['required', Rule::in(['verified', 'restricted', 'blocked', 'archived'])], 'reason' => 'required|string|min:5|max:500']);

        return response()->json($s->setVisitorStatus($r->user(), $visitor, $d['status'], $d['reason']));
    }

    public function setting(Request $r, PublicBookingSetting $setting, PublicBookingService $s): JsonResponse
    {
        $d = $r->validate(['enabled' => 'sometimes|boolean', 'dry_run_checkout_enabled' => 'sometimes|boolean', 'email_verification_required' => 'sometimes|boolean', 'mobile_verification_required' => 'sometimes|boolean', 'payment_required' => 'sometimes|boolean', 'temporary_access_eligible' => 'sometimes|boolean', 'hold_minutes' => 'sometimes|integer|between:2,30', 'maximum_active_holds' => 'sometimes|integer|between:1,10', 'maximum_future_bookings' => 'sometimes|integer|between:1,20', 'maximum_participants' => 'sometimes|integer|between:1,20']);

        return response()->json($s->updateSetting($r->user(), $setting, $d));
    }

    private function bookingData(Request $r): array
    {
        return $r->validate(['starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'lighting_requested' => 'nullable|boolean', 'participants' => 'array|max:8', 'participants.*.name' => 'required|string|max:100', 'participants.*.age_group' => ['nullable', Rule::in(['adult', 'junior'])], 'participants.*.role' => 'nullable|string|max:40', 'participants.*.guardian_consent' => 'nullable|boolean']);
    }
}
