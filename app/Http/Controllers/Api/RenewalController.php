<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembershipPeriod;
use App\Services\RenewalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RenewalController extends Controller
{
    public function dashboard(Request $request, RenewalService $service): JsonResponse
    {
        $data = $request->validate(['membership_period_id' => ['required', 'exists:membership_periods,id'], 'sport_code_id' => ['required', 'exists:sport_codes,id']]);

        return response()->json($service->dashboard($request->user(), MembershipPeriod::findOrFail($data['membership_period_id']), $data['sport_code_id']));
    }

    public function bulk(Request $request, RenewalService $service): JsonResponse
    {
        $data = $request->validate(['membership_period_id' => ['required', 'exists:membership_periods,id'], 'sport_code_id' => ['required', 'exists:sport_codes,id']]);

        return response()->json(['results' => $service->bulkDrafts($request->user(), MembershipPeriod::findOrFail($data['membership_period_id']), $data['sport_code_id'])]);
    }
}
