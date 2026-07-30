<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\MembershipPeriod;
use App\Models\SportCode;
use App\Services\MembershipApplicationService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        abort_unless($request->user()->canAccessSportCode($data['sport_code_id'], true), 403);
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

    public function update(Request $request, MembershipApplication $membershipApplication): JsonResponse
    {
        abort_unless($request->user()->canAccessSportCode($membershipApplication->sport_code_id, true), 403);
        abort_unless(in_array($membershipApplication->status, ['draft', 'information_required'], true), 422);
        $old = $membershipApplication->getAttributes();
        $membershipApplication->update($this->validated($request));
        Audit::record('membership_application.updated', $membershipApplication, $old, $membershipApplication->fresh()->getAttributes());

        return response()->json($membershipApplication);
    }

    public function action(Request $request, MembershipApplication $membershipApplication, MembershipApplicationService $service): JsonResponse
    {
        abort_unless($request->user()->canAccessSportCode($membershipApplication->sport_code_id, true), 403);
        $action = $request->validate(['action' => ['required', Rule::in(['submit', 'begin_review', 'request_information', 'approve', 'reject', 'withdraw', 'cancel'])], 'reason' => ['nullable', 'string', 'max:2000']]);
        if ($action['action'] === 'submit') {
            return response()->json($service->submit($membershipApplication->load(['member', 'period', 'category'])));
        }
        if ($action['action'] === 'approve') {
            return response()->json($service->approve($membershipApplication->id));
        }
        $map = ['begin_review' => ['submitted', 'under_review'], 'request_information' => ['under_review', 'information_required'], 'reject' => ['under_review', 'rejected'], 'withdraw' => ['draft', 'withdrawn'], 'cancel' => ['draft', 'cancelled']];
        [$from, $to] = $map[$action['action']];
        abort_unless($membershipApplication->status === $from, 422, 'Invalid application transition.');
        if ($to === 'rejected') {
            abort_if(blank($action['reason'] ?? null), 422, 'A rejection reason is required.');
        }
        $updates = ['status' => $to];
        if ($to === 'information_required') {
            $updates['information_requested_at'] = now();
        }
        if ($to === 'rejected') {
            $updates += ['rejection_reason' => $action['reason'], 'decided_at' => now(), 'decided_by_user_id' => $request->user()->id];
        }
        if ($to === 'withdrawn') {
            $updates['withdrawn_at'] = now();
        }
        $membershipApplication->update($updates);
        Audit::record("membership_application.$to", $membershipApplication, ['status' => $from], $updates);

        return response()->json($membershipApplication->fresh());
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'member_id' => ['required', 'exists:members,id'], 'sport_code_id' => ['required', 'exists:sport_codes,id'],
            'membership_category_id' => ['required', 'exists:membership_categories,id'], 'membership_period_id' => ['required', 'exists:membership_periods,id'],
            'application_type' => ['required', Rule::in(['new_membership', 'additional_sport', 'renewal', 'category_change', 'reinstatement'])],
            'applicant_notes' => ['nullable', 'string'], 'administrator_notes' => ['nullable', 'string'],
            'joining_fee' => ['nullable', 'numeric', 'min:0'], 'discount' => ['nullable', 'numeric', 'min:0'], 'override_reason' => ['nullable', 'string'],
            'guardian_member_id' => ['nullable', 'exists:members,id'], 'guardian_name' => ['nullable', 'string'], 'guardian_relationship' => ['nullable', 'string'],
            'guardian_email' => ['nullable', 'email'], 'guardian_mobile' => ['nullable', 'string'], 'guardian_consent' => ['boolean'], 'independent_access_permitted' => ['boolean'],
        ]);
        abort_unless(MembershipCategory::whereKey($data['membership_category_id'])->where('sport_code_id', $data['sport_code_id'])->exists(), 422, 'Category does not belong to the selected sport code.');
        $data['joining_fee_cents'] = (int) round(($data['joining_fee'] ?? 0) * 100);
        unset($data['joining_fee']);
        $data['discount_cents'] = (int) round(($data['discount'] ?? 0) * 100);
        unset($data['discount']);

        return $data;
    }
}
