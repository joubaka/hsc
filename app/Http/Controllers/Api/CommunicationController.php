<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationDelivery;
use App\Models\Member;
use App\Models\SportCode;
use App\Services\CommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommunicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        return response()->json([
            'campaigns' => CommunicationCampaign::withCount(['deliveries', 'deliveries as sent_count' => fn ($q) => $q->where('status', 'sent'), 'deliveries as failed_count' => fn ($q) => $q->where('status', 'failed')])->with('creator:id,name')->latest()->limit(100)->get(),
            'deliveries' => CommunicationDelivery::with(['member:id,member_number,first_name,last_name', 'campaign:id,name,type'])->latest()->limit(200)->get(),
            'members' => Member::where('status', 'active')->whereNotNull('email')->orderBy('last_name')->get(['id', 'member_number', 'first_name', 'preferred_name', 'last_name', 'email', 'email_consent']),
            'sport_codes' => SportCode::where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, CommunicationService $service): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'subject' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:20000'],
            'audience_type' => ['required', Rule::in(['all', 'sport_code', 'selected'])],
            'sport_code_id' => ['nullable', 'required_if:audience_type,sport_code', 'exists:sport_codes,id'],
            'member_ids' => ['array', 'required_if:audience_type,selected'], 'member_ids.*' => ['integer', 'exists:members,id'],
            'scheduled_at' => ['nullable', 'date'],
        ]);
        return response()->json($service->announcement($request->user(), $data), 201);
    }

    public function retry(Request $request, CommunicationDelivery $delivery, CommunicationService $service): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        abort_unless($delivery->status === 'failed', 422, 'Only failed deliveries may be retried.');
        $service->retry($delivery);
        return response()->json($delivery->fresh());
    }
}
