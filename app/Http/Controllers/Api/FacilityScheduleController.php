<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Facility;
use App\Models\FacilityClosure;
use App\Models\FacilityOpeningHour;
use App\Services\BookingAccessService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacilityScheduleController extends Controller
{
    public function storeHours(Request $request, Facility $facility): JsonResponse
    {
        abort_unless(app(BookingAccessService::class)->canManage($request->user(), $facility), 403);
        $data = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'], 'opens_at' => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'], 'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'], 'active' => ['required', 'boolean'],
        ]);
        $overlap = FacilityOpeningHour::where('facility_id', $facility->id)->where('active', true)->where('day_of_week', $data['day_of_week'])
            ->where('opens_at', '<', $data['closes_at'])->where('closes_at', '>', $data['opens_at'])->exists();
        abort_if($overlap, 422, 'Opening-hour windows may not overlap.');
        $hours = $facility->openingHours()->create($data);
        Audit::record('facility.opening_hours_created', $hours, [], $data);

        return response()->json($hours, 201);
    }

    public function storeClosure(Request $request, Facility $facility): JsonResponse
    {
        abort_unless(app(BookingAccessService::class)->canManage($request->user(), $facility), 403);
        $data = $request->validate([
            'closure_type' => ['required', 'string', 'max:50'], 'title' => ['required', 'string', 'max:100'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'public_message' => ['nullable', 'string'], 'private_notes' => ['nullable', 'string'],
        ]);
        $closure = $facility->closures()->create([...$data, 'created_by_user_id' => $request->user()->id]);
        $affected = Booking::where('facility_id', $facility->id)->whereIn('status', ['pending_payment', 'confirmed'])
            ->where('starts_at', '<', $closure->ends_at)->where('ends_at', '>', $closure->starts_at)->get(['id', 'booking_reference', 'starts_at', 'ends_at']);
        Audit::record('facility.closure_created', $closure, [], ['facility_id' => $facility->id, 'type' => $closure->closure_type]);

        return response()->json(['closure' => $closure, 'affected_bookings' => $affected], 201);
    }

    public function cancelClosure(Request $request, FacilityClosure $closure): JsonResponse
    {
        abort_unless(app(BookingAccessService::class)->canManage($request->user(), $closure->facility), 403);
        $closure->update(['active' => false, 'cancelled_at' => now(), 'cancelled_by_user_id' => $request->user()->id]);
        Audit::record('facility.closure_cancelled', $closure, ['active' => true], ['active' => false]);

        return response()->json($closure->fresh());
    }
}
