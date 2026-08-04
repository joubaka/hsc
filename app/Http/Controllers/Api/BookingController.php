<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Facility;
use App\Models\Member;
use App\Services\BookingAccessService;
use App\Services\BookingFinancialCompensationService;
use App\Services\BookingRuleEvaluator;
use App\Services\BookingService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $range = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $user = $request->user();
        $query = Booking::with([
            'facility:id,name,code,sport_code_id',
            'member:id,member_number,first_name,last_name',
            'invoice:id,invoice_number,status,total_amount,paid_amount,outstanding_amount',
            'cancellationCompensation.creditNote:id,credit_note_number',
            'cancellationCompensation.refundRequest:id,booking_cancellation_compensation_id,status,entitlement_amount,external_reference',
            'lightingReservations:id,booking_id,status,scheduled_on_at,scheduled_off_at,amount,payment_requirement,failure_code',
            'attendance:id,booking_id,status,check_in_opens_at,check_in_closes_at,checked_in_at,check_in_method',
        ])
            ->when(! $user->isCentralAdministrator(), function ($q) use ($user) {
                if ($user->member_id) {
                    $q->where(fn ($own) => $own->where('member_id', $user->member_id)
                        ->orWhereHas('facility', fn ($f) => $f->whereIn('sport_code_id', $user->sportCodes()->pluck('sport_codes.id'))));
                } else {
                    $q->whereHas('facility', fn ($f) => $f->whereIn('sport_code_id', $user->sportCodes()->pluck('sport_codes.id')));
                }
            })
            ->when($range['from'] ?? null, fn ($q, $from) => $q->where('ends_at', '>', Carbon::parse($from)->startOfDay()))
            ->when($range['to'] ?? null, fn ($q, $to) => $q->where('starts_at', '<', Carbon::parse($to)->addDay()->startOfDay()))
            ->latest('starts_at');

        return response()->json($query->paginate(isset($range['from']) || isset($range['to']) ? 200 : 50));
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $own = $booking->member_id === $request->user()->member_id;
        abort_unless($own || app(BookingAccessService::class)->canView($request->user(), $booking->facility), 403);

        return response()->json($booking->load(['facility.venue', 'participants.attendance', 'invoice.allocations.payment', 'allowanceUsage', 'lightingReservations.zone:id,name,code', 'attendance', 'accessGrants.zone:id,name,code', 'cancellationCompensation.creditNote', 'cancellationCompensation.refundRequest']));
    }

    public function update(Request $request, Booking $booking, BookingRuleEvaluator $evaluator): JsonResponse
    {
        $data = $request->validate([
            'facility_id' => ['required', 'exists:facilities,id'], 'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'], 'booked_for_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lighting_on_at' => ['nullable', 'required_with:lighting_off_at', 'date'],
            'lighting_off_at' => ['nullable', 'required_with:lighting_on_at', 'date', 'after:lighting_on_at'],
        ]);
        $facility = Facility::findOrFail($data['facility_id']);
        abort_unless(app(BookingAccessService::class)->canManage($request->user(), $booking->facility)
            && app(BookingAccessService::class)->canManage($request->user(), $facility), 403);
        abort_unless(in_array($booking->status, ['confirmed', 'pending_payment'], true) && $booking->starts_at->isFuture(), 422, 'Only future active bookings can be edited.');
        abort_if(! $booking->member_id && blank($data['booked_for_name'] ?? $booking->booked_for_name), 422, 'Enter the non-member name.');
        $scheduleChanged = (int) $booking->facility_id !== (int) $facility->id
            || ! $booking->starts_at->equalTo(Carbon::parse($data['starts_at'])) || ! $booking->ends_at->equalTo(Carbon::parse($data['ends_at']));
        $member = $booking->member;
        $profile = $member ? null : ['profile' => 'public_visitor', 'member_id' => null, 'age' => null, 'sport_membership_id' => null, 'membership_category_id' => null, 'failures' => [], 'eligible' => true];
        $hasLighting = $booking->lightingReservations()->whereNotIn('status', ['cancelled', 'off'])->exists();
        $result = $evaluator->evaluate($request->user(), $member, $facility, Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']), $booking->guest_count, null, $booking->id, $hasLighting, $profile);
        abort_unless($result['eligible'], 422, $result['failures'][0]['member_message'] ?? 'The edited booking is not allowed.');
        abort_if($hasLighting && ! ($result['lighting']['applies'] ?? false), 422, 'Lighting is not available for the selected court and time. Choose another slot or cancel the lighting reservation first.');
        if ($hasLighting && isset($data['lighting_on_at'], $data['lighting_off_at'])) {
            $originalLightingAmount = (int) ($result['price']['lighting_amount'] ?? 0);
            $result['lighting'] = app(\App\Services\LightingQuoteService::class)->withCustomWindow($result['lighting'], Carbon::parse($data['lighting_on_at']), Carbon::parse($data['lighting_off_at']), Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']));
            $result['price']['lighting_amount'] = (int) $result['lighting']['amount'];
            $difference = $result['price']['lighting_amount'] - $originalLightingAmount;
            $result['price']['gross_amount'] += $difference;
            $result['price']['total_amount'] += $difference;
        }
        $legacyFreeLighting = $hasLighting && $booking->total_amount === 0 && $booking->payment_status === 'not_required';
        if ($legacyFreeLighting) {
            $lightingAmount = (int) ($result['price']['lighting_amount'] ?? 0);
            $result['price']['allowance_amount'] = max(0, $result['price']['gross_amount'] - $lightingAmount);
            $result['price']['total_amount'] = $lightingAmount;
            $result['allowance']['minutes'] = 0;
            $result['allowance']['value_cents'] = 0;
        }
        abort_unless($legacyFreeLighting || (int) $result['price']['total_amount'] === (int) $booking->price_snapshot['total_amount'], 422, 'The selected lighting window changes the amount due. Cancel and create a new booking so billing remains correct.');

        return response()->json(DB::transaction(function () use ($request, $booking, $facility, $data, $result, $scheduleChanged, $hasLighting) {
            $booking = Booking::lockForUpdate()->findOrFail($booking->id);
            $before = $booking->only(['facility_id', 'starts_at', 'ends_at', 'booked_for_name', 'notes']);
            $booking->update([
                'facility_id' => $facility->id, 'sport_code_id' => $facility->sport_code_id,
                'starts_at' => Carbon::parse($data['starts_at']), 'ends_at' => Carbon::parse($data['ends_at']),
                'duration_minutes' => Carbon::parse($data['starts_at'])->diffInMinutes(Carbon::parse($data['ends_at'])),
                'booked_for_name' => $booking->member_id ? null : trim($data['booked_for_name'] ?? $booking->booked_for_name),
                'notes' => $data['notes'] ?? null, 'gross_amount' => $result['price']['gross_amount'], 'allowance_amount' => $result['price']['allowance_amount'], 'total_amount' => $result['price']['total_amount'],
                'status' => $result['price']['total_amount'] > 0 && $booking->payment_status !== 'paid' ? 'pending_payment' : $booking->status,
                'payment_status' => $result['price']['total_amount'] > 0 && $booking->payment_status === 'not_required' ? 'unpaid' : $booking->payment_status,
                'rule_snapshot' => ['profile' => $result['profile'], 'policies' => $result['policies'], 'checks' => $result['checks']],
                'price_snapshot' => $result['price'], 'allowance_snapshot' => $result['allowance'], 'cancellation_snapshot' => $result['cancellation'],
                'lighting_snapshot' => $result['lighting'],
            ]);
            if ($hasLighting && $scheduleChanged) {
                app(\App\Services\LightingReservationService::class)->reschedule($booking, $result['lighting']);
            }
            if ($booking->invoice) {
                $booking->invoice->lines()->where('source_type', Booking::class)->where('source_id', $booking->id)->where('line_type', 'facility')->update(['sport_code_id' => $facility->sport_code_id, 'description' => "{$facility->name} court rental"]);
            }
            if ($booking->total_amount > 0 && ! $booking->invoice_id) {
                app(\App\Services\BookingBillingService::class)->createInvoice($request->user(), $booking);
            }
            Audit::record('booking.edited', $booking, $before, $booking->only(array_keys($before)));
            if ($scheduleChanged && $booking->attendance) {
                $policy = app(\App\Services\BookingCheckInPolicyResolver::class)->resolve($booking);
                $booking->attendance->update(['status' => now()->lt($policy['opens_at']) ? 'not_open' : 'open', 'check_in_required' => $policy['check_in_required'], 'check_in_opens_at' => $policy['opens_at'], 'check_in_closes_at' => $policy['closes_at'], 'policy_snapshot' => $policy]);
                \App\Models\BookingCheckInToken::where('booking_id', $booking->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            } else {
                app(\App\Services\BookingCheckInService::class)->ensure($booking);
            }

            return $booking->fresh(['facility', 'member', 'invoice', 'attendance']);
        }, 3));
    }

    public function simulate(Request $request, BookingRuleEvaluator $evaluator): JsonResponse
    {
        $data = $this->slotData($request);
        $facility = Facility::findOrFail($data['facility_id']);
        abort_unless(app(BookingAccessService::class)->canView($request->user(), $facility), 403);
        $member = ! empty($data['member_id']) ? Member::findOrFail($data['member_id']) : null;
        $profile = $member ? null : ['profile' => 'public_visitor', 'member_id' => null, 'age' => null, 'sport_membership_id' => null, 'membership_category_id' => null, 'failures' => [], 'eligible' => true];

        return response()->json($evaluator->evaluate($request->user(), $member, $facility, Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']), $data['guest_count'] ?? 0, null, null, (bool) ($data['lighting_requested'] ?? false), $profile));
    }

    public function hold(Request $request, BookingService $service): JsonResponse
    {
        $data = $this->slotData($request) + $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $member = ! empty($data['member_id']) ? Member::findOrFail($data['member_id']) : null;
        $facility = Facility::findOrFail($data['facility_id']);
        abort_unless(($member && $request->user()->member_id === $member->id) || app(BookingAccessService::class)->canManage($request->user(), $facility), 403);
        abort_if(! $member && blank($data['booked_for_name'] ?? null), 422, 'Enter the non-member name.');

        return response()->json($service->createHold($request->user(), $facility, $member, $data), 201);
    }

    public function confirm(Request $request, BookingHold $hold, BookingService $service): JsonResponse
    {
        $data = $request->validate([
            'confirmation_key' => ['required', 'uuid'], 'participants' => ['array', 'max:12'],
            'billing_strategy' => ['nullable', 'in:invoice_each_occurrence,no_charge'],
            'participants.*.member_id' => ['nullable', 'exists:members,id'], 'participants.*.participant_type' => ['required_with:participants', 'in:member,guest'],
            'participants.*.display_name' => ['required_with:participants', 'string', 'max:100'],
        ]);
        $billingStrategy = $data['billing_strategy'] ?? 'invoice_each_occurrence';
        if ($billingStrategy === 'no_charge') {
            abort_unless(app(BookingAccessService::class)->canManage($request->user(), $hold->facility), 403, 'Only an administrator may waive a booking fee.');
        }
        if (! $hold->member_id) {
            abort_unless($billingStrategy === 'no_charge', 422, 'Custom-name bookings must be marked no fee because there is no member account to invoice.');
        }

        return response()->json($service->confirm($request->user(), $hold, $data['confirmation_key'], $data['participants'] ?? [], $billingStrategy), 201);
    }

    public function recognizePayment(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        return response()->json($service->recognizePayment($request->user(), $booking));
    }

    public function cancellationPreview(Request $request, Booking $booking, BookingFinancialCompensationService $service): JsonResponse
    {
        return response()->json($service->preview($request->user(), $booking));
    }

    public function cancel(Request $request, Booking $booking, BookingFinancialCompensationService $service): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        return response()->json($service->cancel($request->user(), $booking, $data['reason'], $data['idempotency_key']));
    }

    private function slotData(Request $request): array
    {
        return $request->validate([
            'facility_id' => ['required', 'exists:facilities,id'], 'member_id' => ['nullable', 'required_without:booked_for_name', 'exists:members,id'],
            'booked_for_name' => ['nullable', 'required_without:member_id', 'string', 'min:2', 'max:120'],
            'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'guest_count' => ['nullable', 'integer', 'between:0,20'],
            'lighting_requested' => ['nullable', 'boolean'],
        ]);
    }
}
