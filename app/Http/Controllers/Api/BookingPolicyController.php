<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingAllowancePolicy;
use App\Models\BookingPolicy;
use App\Models\BookingPricingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingPolicyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator() || $request->user()->sportCodes()->exists(), 403);

        return response()->json([
            'policies' => BookingPolicy::orderBy('policy_type')->get(),
            'pricing_rules' => BookingPricingRule::orderByDesc('priority')->get(),
            'allowances' => BookingAllowancePolicy::get(),
        ]);
    }

    public function storePolicy(Request $request): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        $data = $request->validate([
            'name' => ['required', 'string'], 'policy_type' => ['required', 'in:access,window,duration,quota,cancellation,approval'],
            'scope_type' => ['required', 'in:central,sport_code,facility,facility_group'], 'scope_id' => ['nullable', 'integer'],
            'profile' => ['nullable', 'string'], 'membership_category_id' => ['nullable', 'exists:membership_categories,id'],
            'rules' => ['required', 'array'], 'priority' => ['integer'], 'version' => ['integer', 'min:1'], 'active' => ['boolean'],
        ]);

        return response()->json(BookingPolicy::create($data), 201);
    }

    public function storePricing(Request $request): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        $data = $request->validate([
            'name' => ['required', 'string'], 'scope_type' => ['required', 'in:central,sport_code,facility,facility_group'],
            'scope_id' => ['nullable', 'integer'], 'profile' => ['nullable', 'string'], 'membership_category_id' => ['nullable', 'exists:membership_categories,id'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'], 'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'], 'pricing_method' => ['required', 'in:hourly,fixed,per_participant,per_guest'],
            'amount_cents' => ['required', 'integer', 'min:0'], 'guest_fee_cents' => ['integer', 'min:0'],
            'priority' => ['integer'], 'peak' => ['boolean'], 'active' => ['boolean'],
        ]);

        return response()->json(BookingPricingRule::create($data), 201);
    }
}
