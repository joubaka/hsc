<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MembershipPeriod;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MembershipPeriodController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(MembershipPeriod::latest('starts_on')->get());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        $period = MembershipPeriod::create($this->validated($request));
        Audit::record('membership_period.created', $period, [], $period->getAttributes());

        return response()->json($period, 201);
    }

    public function update(Request $request, MembershipPeriod $membershipPeriod): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        abort_if($membershipPeriod->status === 'archived', 422, 'Archived periods are read-only.');
        $old = $membershipPeriod->getAttributes();
        $membershipPeriod->update($this->validated($request));
        Audit::record('membership_period.updated', $membershipPeriod, $old, $membershipPeriod->fresh()->getAttributes());

        return response()->json($membershipPeriod);
    }

    public function transition(Request $request, MembershipPeriod $membershipPeriod): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        $target = $request->validate(['status' => ['required', Rule::in(['open', 'closed', 'archived'])]])['status'];
        $allowed = ['draft' => ['open'], 'open' => ['closed'], 'closed' => ['open', 'archived']];
        abort_unless(in_array($target, $allowed[$membershipPeriod->status] ?? [], true), 422, 'Invalid period status transition.');
        $old = $membershipPeriod->status;
        $membershipPeriod->update(['status' => $target]);
        Audit::record("membership_period.$target", $membershipPeriod, ['status' => $old], ['status' => $target]);

        return response()->json($membershipPeriod);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'applications_open_on' => ['nullable', 'date', 'before_or_equal:applications_close_on'],
            'applications_close_on' => ['nullable', 'date', 'before_or_equal:ends_on'],
            'renewal_deadline' => ['nullable', 'date', 'before_or_equal:ends_on'],
            'status' => ['required', Rule::in(['draft', 'open', 'closed', 'archived'])],
        ]);
    }
}
