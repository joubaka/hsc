<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Facility;
use App\Models\Member;
use App\Services\BookingAccessService;
use App\Services\BookingRuleEvaluator;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Booking::with(['facility:id,name,code,sport_code_id', 'invoice:id,invoice_number,status,total_amount,outstanding_amount'])
            ->when(! $user->isCentralAdministrator(), function ($q) use ($user) {
                if ($user->member_id) {
                    $q->where(fn ($own) => $own->where('member_id', $user->member_id)
                        ->orWhereHas('facility', fn ($f) => $f->whereIn('sport_code_id', $user->sportCodes()->pluck('sport_codes.id'))));
                } else {
                    $q->whereHas('facility', fn ($f) => $f->whereIn('sport_code_id', $user->sportCodes()->pluck('sport_codes.id')));
                }
            })->latest('starts_at');

        return response()->json($query->paginate(50));
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $own = $booking->member_id === $request->user()->member_id;
        abort_unless($own || app(BookingAccessService::class)->canView($request->user(), $booking->facility), 403);

        return response()->json($booking->load(['facility.venue', 'participants', 'invoice.allocations.payment', 'allowanceUsage']));
    }

    public function simulate(Request $request, BookingRuleEvaluator $evaluator): JsonResponse
    {
        $data = $this->slotData($request);
        $facility = Facility::findOrFail($data['facility_id']);
        abort_unless(app(BookingAccessService::class)->canView($request->user(), $facility), 403);
        $member = Member::findOrFail($data['member_id']);

        return response()->json($evaluator->evaluate($request->user(), $member, $facility, Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']), $data['guest_count'] ?? 0));
    }

    public function hold(Request $request, BookingService $service): JsonResponse
    {
        $data = $this->slotData($request) + $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $member = Member::findOrFail($data['member_id']);
        $facility = Facility::findOrFail($data['facility_id']);
        abort_unless($request->user()->member_id === $member->id || app(BookingAccessService::class)->canManage($request->user(), $facility), 403);

        return response()->json($service->createHold($request->user(), $facility, $member, $data), 201);
    }

    public function confirm(Request $request, BookingHold $hold, BookingService $service): JsonResponse
    {
        $data = $request->validate([
            'confirmation_key' => ['required', 'uuid'], 'participants' => ['array', 'max:12'],
            'participants.*.member_id' => ['nullable', 'exists:members,id'], 'participants.*.participant_type' => ['required_with:participants', 'in:member,guest'],
            'participants.*.display_name' => ['required_with:participants', 'string', 'max:100'],
        ]);

        return response()->json($service->confirm($request->user(), $hold, $data['confirmation_key'], $data['participants'] ?? []), 201);
    }

    public function recognizePayment(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        return response()->json($service->recognizePayment($request->user(), $booking));
    }

    public function cancel(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json($service->cancel($request->user(), $booking, $data['reason']));
    }

    private function slotData(Request $request): array
    {
        return $request->validate([
            'facility_id' => ['required', 'exists:facilities,id'], 'member_id' => ['required', 'exists:members,id'],
            'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'guest_count' => ['nullable', 'integer', 'between:0,20'],
        ]);
    }
}
