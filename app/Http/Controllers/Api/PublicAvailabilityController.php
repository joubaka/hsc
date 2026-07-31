<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BookingAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PublicAvailabilityController extends Controller
{
    public function __invoke(Request $request, BookingAvailabilityService $service): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from'], 'sport_code_id' => ['nullable', 'integer', 'exists:sport_codes,id']]);
        abort_if(Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 14, 422, 'Availability range may not exceed 14 days.');

        return response()->json(['facilities' => $service->publicSchedule(Carbon::parse($data['from']), Carbon::parse($data['to']), $data['sport_code_id'] ?? null)]);
    }
}
