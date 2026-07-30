<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialReportController extends Controller
{
    public function statement(Request $request, int $member, FinancialReportService $service): JsonResponse
    {
        return response()->json($service->statement($request->user(), $member));
    }

    public function outstanding(Request $request, FinancialReportService $service): JsonResponse
    {
        return response()->json($service->outstanding($request->user()));
    }

    public function sport(Request $request, int $sportCode, FinancialReportService $service): JsonResponse
    {
        return response()->json($service->sportCode($request->user(), $sportCode));
    }

    public function levy(Request $request, FinancialReportService $service): JsonResponse
    {
        return response()->json($service->levy($request->user()));
    }
}
