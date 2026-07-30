<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFinancialSettingsRequest;
use App\Models\FinancialSetting;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);

        return response()->json(FinancialSetting::firstOrCreate([])->fresh());
    }

    public function update(UpdateFinancialSettingsRequest $request): JsonResponse
    {
        $settings = FinancialSetting::firstOrCreate([])->fresh();
        $old = $settings->getAttributes();
        $settings->update($request->validated());
        Audit::record('financial_settings.updated', $settings, $old, $settings->fresh()->getAttributes());

        return response()->json($settings->fresh());
    }
}
