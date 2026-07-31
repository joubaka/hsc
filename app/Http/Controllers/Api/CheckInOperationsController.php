<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceCorrectionRequest;
use App\Http\Requests\EmergencyAccessRequest;
use App\Models\AccessEmergencyAction;
use App\Models\BookingAttendance;
use App\Models\Facility;
use App\Services\CheckInOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInOperationsController extends Controller
{
    public function dashboard(Request $r, CheckInOperationsService $s): JsonResponse
    {
        abort_unless($r->user()->isCentralAdministrator() || $r->user()->sportCodes()->exists(), 403);

        return response()->json($s->dashboard($r->user()));
    }

    public function rotateFacility(Request $r, Facility $facility, CheckInOperationsService $s): JsonResponse
    {
        return response()->json($s->rotateFacilityToken($r->user(), $facility), 201);
    }

    public function revokeFacility(Request $r, Facility $facility, CheckInOperationsService $s): JsonResponse
    {
        $s->revokeFacilityToken($r->user(), $facility);

        return response()->json(['configured' => false]);
    }

    public function candidates(Request $r, string $token, CheckInOperationsService $s): JsonResponse
    {
        return response()->json(['candidates' => $s->facilityCandidates($r->user(), $token)]);
    }

    public function facilityCheckIn(Request $r, string $token, CheckInOperationsService $s): JsonResponse
    {
        $d = $r->validate(['booking_id' => ['required', 'integer', 'exists:bookings,id']]);

        return response()->json($s->facilityCheckIn($r->user(), $token, $d['booking_id']));
    }

    public function correct(AttendanceCorrectionRequest $r, BookingAttendance $attendance, CheckInOperationsService $s): JsonResponse
    {
        return response()->json($s->correct($r->user(), $attendance, $r->validated()));
    }

    public function suspend(EmergencyAccessRequest $r, CheckInOperationsService $s): JsonResponse
    {
        return response()->json($s->suspend($r->user(), $r->validated()), 201);
    }

    public function restore(Request $r, AccessEmergencyAction $action, CheckInOperationsService $s): JsonResponse
    {
        $d = $r->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);

        return response()->json($s->restore($r->user(), $action, $d['reason']));
    }
}
