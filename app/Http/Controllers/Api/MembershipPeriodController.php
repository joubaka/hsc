<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMembershipPeriodRequest;
use App\Http\Requests\TransitionMembershipPeriodRequest;
use App\Http\Requests\UpdateMembershipPeriodRequest;
use App\Models\MembershipPeriod;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;

class MembershipPeriodController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(MembershipPeriod::latest('starts_on')->get());
    }

    public function store(StoreMembershipPeriodRequest $request): JsonResponse
    {
        $period = MembershipPeriod::create($request->validated());
        Audit::record('membership_period.created', $period, [], $period->getAttributes());

        return response()->json($period, 201);
    }

    public function update(UpdateMembershipPeriodRequest $request, MembershipPeriod $membershipPeriod): JsonResponse
    {
        $old = $membershipPeriod->getAttributes();
        $membershipPeriod->update($request->validated());
        Audit::record('membership_period.updated', $membershipPeriod, $old, $membershipPeriod->fresh()->getAttributes());

        return response()->json($membershipPeriod);
    }

    public function transition(TransitionMembershipPeriodRequest $request, MembershipPeriod $membershipPeriod): JsonResponse
    {
        $target = $request->validated('status');
        $allowed = ['draft' => ['open'], 'open' => ['closed'], 'closed' => ['open', 'archived']];
        abort_unless(in_array($target, $allowed[$membershipPeriod->status] ?? [], true), 422, 'Invalid period status transition.');
        $old = $membershipPeriod->status;
        $membershipPeriod->update(['status' => $target]);
        Audit::record("membership_period.$target", $membershipPeriod, ['status' => $old], ['status' => $target]);

        return response()->json($membershipPeriod);
    }
}
