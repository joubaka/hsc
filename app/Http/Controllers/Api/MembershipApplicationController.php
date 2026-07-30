<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MembershipApplicationActionRequest;
use App\Http\Requests\StoreMembershipApplicationRequest;
use App\Http\Requests\UpdateMembershipApplicationRequest;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\MembershipPeriod;
use App\Models\SportCode;
use App\Services\MembershipApplicationService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(MembershipApplication::with(['member', 'sportCode', 'category', 'period', 'decidedBy'])
            ->when(! $user->isCentralAdministrator(), fn ($q) => $q->whereIn('sport_code_id', $user->sportCodes()->pluck('sport_codes.id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('sport_code_id'), fn ($q) => $q->where('sport_code_id', $request->integer('sport_code_id')))
            ->latest()->paginate(25));
    }

    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();
        $codes = SportCode::with('categories')->when(! $user->isCentralAdministrator(), fn ($q) => $q->whereIn('id', $user->sportCodes()->pluck('sport_codes.id')))->get();

        return response()->json(['members' => Member::orderBy('last_name')->get(['id', 'member_number', 'first_name', 'last_name', 'date_of_birth']), 'sport_codes' => $codes, 'periods' => MembershipPeriod::whereIn('status', ['draft', 'open'])->orderByDesc('starts_on')->get()]);
    }

    public function store(StoreMembershipApplicationRequest $request): JsonResponse
    {
        $data = $request->applicationData();
        $duplicate = MembershipApplication::where([
            'member_id' => $data['member_id'], 'sport_code_id' => $data['sport_code_id'],
            'membership_period_id' => $data['membership_period_id'], 'application_type' => $data['application_type'],
        ])->whereIn('status', ['draft', 'submitted', 'under_review', 'information_required'])->exists();
        abort_if($duplicate, 422, 'An unresolved application already exists for this outcome.');
        if (($data['discount_cents'] ?? 0) > 0) {
            abort_unless($request->user()->isCentralAdministrator() && $request->filled('override_reason'), 403);
        }
        $application = MembershipApplication::create($data);
        Audit::record('membership_application.created', $application, [], $application->getAttributes());

        return response()->json($application->load(['member', 'sportCode', 'category', 'period']), 201);
    }

    public function show(Request $request, MembershipApplication $membershipApplication): JsonResponse
    {
        abort_unless($request->user()->canAccessSportCode($membershipApplication->sport_code_id), 403);

        return response()->json($membershipApplication->load(['member.household.members', 'sportCode', 'category', 'period', 'decidedBy', 'sportMembership']));
    }

    public function audit(Request $request, MembershipApplication $membershipApplication): JsonResponse
    {
        abort_unless($request->user()->can('view', $membershipApplication), 403);

        return response()->json(AuditLog::with('user:id,name')
            ->where(function ($query) use ($membershipApplication) {
                $query->where(['auditable_type' => MembershipApplication::class, 'auditable_id' => $membershipApplication->id]);
                if ($membershipApplication->sportMembership) {
                    $query->orWhere(['auditable_type' => \App\Models\SportMembership::class, 'auditable_id' => $membershipApplication->sportMembership->id]);
                }
            })->oldest()->get(['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'created_at']));
    }

    public function update(UpdateMembershipApplicationRequest $request, MembershipApplication $membershipApplication): JsonResponse
    {
        $old = $membershipApplication->getAttributes();
        $membershipApplication->update($request->applicationData());
        Audit::record('membership_application.updated', $membershipApplication, $old, $membershipApplication->fresh()->getAttributes());

        return response()->json($membershipApplication);
    }

    public function action(MembershipApplicationActionRequest $request, MembershipApplication $membershipApplication, MembershipApplicationService $service): JsonResponse
    {
        $action = $request->validated();

        return response()->json($service->transition($membershipApplication, $action['action'], $action['reason'] ?? null, $action['administrator_notes'] ?? null));
    }
}
