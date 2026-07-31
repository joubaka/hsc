<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessAlert;
use App\Models\AccessCommand;
use App\Models\AccessEndpoint;
use App\Models\AccessZone;
use App\Models\BookingAccessGrant;
use App\Services\AccessCommandDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessController extends Controller
{
    public function dashboard(Request $r): JsonResponse
    {
        abort_unless($r->user()->isCentralAdministrator() || $r->user()->sportCodes()->exists(), 403);

        return response()->json(['zones' => AccessZone::with(['facilities:id,name,code', 'endpoints'])->get(), 'grants' => BookingAccessGrant::with(['booking:id,booking_reference,sport_code_id', 'zone:id,name,code'])->latest()->limit(100)->get(), 'commands' => AccessCommand::with('endpoint:id,name,code')->latest()->limit(100)->get(), 'alerts' => AccessAlert::latest()->limit(100)->get()]);
    }

    public function dispatch(Request $r, AccessCommandDispatcher $s): JsonResponse
    {
        abort_unless($r->user()->isCentralAdministrator(), 403);

        return response()->json($s->dispatch());
    }

    public function endpoint(Request $r): JsonResponse
    {
        abort_unless($r->user()->isCentralAdministrator(), 403);
        $d = $r->validate(['access_zone_id' => ['required', 'exists:access_zones,id'], 'name' => ['required', 'string', 'max:100'], 'code' => ['required', 'alpha_dash', 'max:50', 'unique:access_endpoints,code'], 'provider' => ['required', 'in:null,dry_run,manual'], 'enabled' => ['boolean'], 'dry_run' => ['required', 'accepted']]);

        return response()->json(AccessEndpoint::create($d + ['enabled' => $d['enabled'] ?? false]), 201);
    }
}
