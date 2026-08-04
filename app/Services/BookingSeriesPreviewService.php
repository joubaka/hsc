<?php

namespace App\Services;

use App\Models\BookingSeries;
use App\Models\Facility;
use App\Models\Member;
use App\Models\User;

class BookingSeriesPreviewService
{
    public function __construct(
        private BookingRecurrenceExpansionService $expander,
        private BookingRuleEvaluator $evaluator,
    ) {}

    public function preview(User $actor, BookingSeries|array $definition, array $ignoreBookingIds = []): array
    {
        $values = $definition instanceof BookingSeries ? $definition->toArray() : $definition;
        $facility = Facility::findOrFail($values['facility_id']);
        $member = empty($values['member_id']) ? null : Member::findOrFail($values['member_id']);
        abort_unless(app(BookingAccessService::class)->canView($actor, $facility), 403);
        abort_unless(! $member || $actor->member_id === $member->id || app(BookingAccessService::class)->canManage($actor, $facility), 403);

        $acceptedFuture = 0;
        $acceptedByDay = [];
        $allowanceByPeriod = [];
        $rows = [];
        foreach ($this->expander->expand($values) as $slot) {
            $profile = $member ? null : ['profile' => 'public_visitor', 'member_id' => null, 'age' => null, 'sport_membership_id' => null, 'membership_category_id' => null, 'failures' => [], 'eligible' => true];
            $result = $this->evaluator->evaluate($actor, $member, $facility, $slot['starts_at'], $slot['ends_at'], (int) ($values['guest_count'] ?? 0), null, $ignoreBookingIds, (bool) ($values['lighting_requested'] ?? false), $profile);
            // Ad-hoc member court hire is free. Centrally managed, invoiced series (league/team/coach blocks)
            // are an administrative product and retain their configured series price.
            if (($values['billing_strategy'] ?? null) === 'one_invoice_for_series' && app(BookingAccessService::class)->canManage($actor, $facility)) {
                $result['allowance'] = ['policy_id' => null, 'minutes' => 0, 'value_cents' => 0];
                $result['price']['allowance'] = $result['allowance'];
                $result['price']['allowance_amount'] = 0;
                $result['price']['member_court_waiver_amount'] = 0;
                $result['price']['total_amount'] = $result['price']['gross_amount'];
                $result['price']['court_amount'] = $result['price']['gross_amount'] - (int) ($result['price']['lighting_amount'] ?? 0);
            }
            $failures = $result['failures'];
            $futureLimit = collect($result['checks'])->firstWhere('code', 'future_quota');
            $dailyLimit = collect($result['checks'])->firstWhere('code', 'daily_quota');
            if ($futureLimit && $futureLimit['passed'] && $futureLimit['limit'] <= $futureLimit['actual'] + $acceptedFuture) {
                $failures[] = ['code' => 'series_future_quota', 'member_message' => 'This occurrence exceeds your future booking quota.'];
            }
            $day = $slot['scheduled_date'];
            if ($dailyLimit && $dailyLimit['limit'] !== null && $dailyLimit['passed']
                && $dailyLimit['limit'] <= $dailyLimit['actual'] + ($acceptedByDay[$day] ?? 0)) {
                $failures[] = ['code' => 'series_daily_quota', 'member_message' => 'This occurrence exceeds your daily booking quota.'];
            }
            $allowance = $result['allowance'];
            if (($allowance['policy_id'] ?? null) && $allowance['minutes'] > 0) {
                $periodKey = $allowance['policy_id'].'|'.$allowance['period_starts_on'];
                $alreadyProposed = $allowanceByPeriod[$periodKey] ?? 0;
                $remaining = max(0, $allowance['minutes'] - $alreadyProposed);
                $covered = min($result['price']['duration_minutes'], $remaining);
                $value = intdiv($result['price']['gross_amount'] * $covered, $result['price']['duration_minutes']);
                $allowance['minutes'] = $covered;
                $allowance['value_cents'] = $value;
                $result['price']['allowance_amount'] = $value;
                $result['price']['total_amount'] = $result['price']['gross_amount'] - $value;
                $allowanceByPeriod[$periodKey] = $alreadyProposed + $covered;
            }
            $available = $failures === [];
            if ($available) {
                $acceptedFuture++;
                $acceptedByDay[$day] = ($acceptedByDay[$day] ?? 0) + 1;
            }
            $requiresApproval = count($this->expander->expand($values)) > 10 || ! app(BookingAccessService::class)->canManage($actor, $facility);
            $rows[] = [
                'occurrence_key' => hash('sha256', implode('|', [$day, $slot['starts_at']->toIso8601String(), $facility->id, $values['version'] ?? 1])),
                'occurrence_number' => $slot['number'], 'local_date' => $day,
                'starts_at' => $slot['starts_at']->toIso8601String(), 'ends_at' => $slot['ends_at']->toIso8601String(),
                'starts_at_utc' => $slot['starts_at']->copy()->utc()->toIso8601String(), 'ends_at_utc' => $slot['ends_at']->copy()->utc()->toIso8601String(),
                'facility' => $facility->only(['id', 'name', 'code', 'sport_code_id']),
                'status' => $available ? 'available' : (collect($failures)->contains(fn ($f) => str_contains($f['code'], 'conflict')) ? 'conflicted' : 'rule_blocked'),
                'eligible' => $available, 'failures' => collect($failures)->map(fn ($failure) => collect($failure)->only(['code', 'member_message', 'override_possible'])->all())->values(),
                'checks' => $result['checks'], 'profile' => $result['profile'], 'price' => $result['price'],
                'allowance' => $allowance, 'amount_payable' => $result['price']['total_amount'],
                'cancellation' => $result['cancellation'], 'approval_required' => $requiresApproval,
            ];
        }
        $collection = collect($rows);

        return ['occurrences' => $rows, 'summary' => [
            'requested' => count($rows), 'available' => $collection->where('status', 'available')->count(),
            'conflicted' => $collection->where('status', 'conflicted')->count(), 'rule_blocked' => $collection->where('status', 'rule_blocked')->count(),
            'approval_required' => $collection->where('approval_required', true)->count(),
            'allowance_covered' => $collection->filter(fn ($r) => $r['allowance']['minutes'] === $r['price']['duration_minutes'])->count(),
            'partially_allowance_covered' => $collection->filter(fn ($r) => $r['allowance']['minutes'] > 0 && $r['allowance']['minutes'] < $r['price']['duration_minutes'])->count(),
            'fully_chargeable' => $collection->where('allowance.minutes', 0)->count(),
            'estimated_total_amount' => $collection->sum('amount_payable'),
            'estimated_allowance_minutes' => $collection->sum('allowance.minutes'),
        ]];
    }
}
