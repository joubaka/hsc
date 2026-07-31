<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingSeries;
use App\Models\Facility;
use App\Services\BookingAccessService;
use App\Services\BookingSeriesMaterializationService;
use App\Services\BookingSeriesPreviewService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BookingSeriesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(BookingSeries::with(['facility:id,name,code,sport_code_id', 'member:id,member_number,first_name,last_name'])
            ->withCount('occurrences')
            ->when(! $user->isCentralAdministrator(), fn ($q) => $q->where(fn ($scope) => $scope
                ->when($user->member_id, fn ($own) => $own->where('member_id', $user->member_id))
                ->orWhereIn('sport_code_id', $user->sportCodes()->pluck('sport_codes.id'))))
            ->latest()->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->definition($request);
        $facility = Facility::findOrFail($data['facility_id']);
        abort_unless($facility->sport_code_id && (int) $facility->sport_code_id === (int) $data['sport_code_id'], 422, 'Sport code must match the facility.');
        abort_unless($request->user()->member_id === (int) $data['member_id'] || app(BookingAccessService::class)->canManage($request->user(), $facility), 403);
        if ($existing = BookingSeries::where('idempotency_key', $data['idempotency_key'])->first()) {
            return response()->json($existing);
        }
        $series = BookingSeries::create([...$data, 'series_reference' => 'SER-'.strtoupper(Str::random(10)), 'created_by_user_id' => $request->user()->id, 'status' => 'draft', 'approval_status' => 'draft', 'version' => 1]);
        Audit::record('booking_series.draft_created', $series, [], ['series_reference' => $series->series_reference, 'facility_id' => $series->facility_id, 'member_id' => $series->member_id]);

        return response()->json($series, 201);
    }

    public function show(Request $request, BookingSeries $bookingSeries): JsonResponse
    {
        $this->authorizeView($request, $bookingSeries);

        return response()->json($bookingSeries->load(['facility', 'member', 'occurrences.booking.invoice']));
    }

    public function preview(Request $request, BookingSeries $bookingSeries, BookingSeriesPreviewService $service): JsonResponse
    {
        $this->authorizeView($request, $bookingSeries);
        $preview = $service->preview($request->user(), $bookingSeries);

        return response()->json($preview);
    }

    public function submit(Request $request, BookingSeries $bookingSeries, BookingSeriesPreviewService $service): JsonResponse
    {
        $this->authorizeOwner($request, $bookingSeries);
        abort_unless(in_array($bookingSeries->approval_status, ['draft', 'information_required'], true), 422);
        $preview = $service->preview($request->user(), $bookingSeries);
        $bookingSeries->update([
            'status' => 'submitted', 'approval_status' => 'submitted', 'submitted_at' => now(),
            'rules_snapshot' => $preview['summary'], 'pricing_strategy_snapshot' => ['billing_strategy' => $bookingSeries->billing_strategy, 'estimated_total_amount' => $preview['summary']['estimated_total_amount']],
            'cancellation_policy_snapshot' => collect($preview['occurrences'])->pluck('cancellation')->unique()->values(),
        ]);
        Audit::record('booking_series.submitted', $bookingSeries, [], ['version' => $bookingSeries->version, 'summary' => $preview['summary']]);

        return response()->json($bookingSeries->fresh());
    }

    public function action(Request $request, BookingSeries $bookingSeries, BookingSeriesMaterializationService $materializer): JsonResponse
    {
        $data = $request->validate(['action' => 'required|in:begin_review,request_information,approve,reject,withdraw,cancel', 'reason' => 'nullable|string|max:500', 'partial' => 'boolean', 'occurrence_numbers' => 'array', 'occurrence_numbers.*' => 'integer|min:1|max:104']);
        if (in_array($data['action'], ['withdraw'], true)) {
            $this->authorizeOwner($request, $bookingSeries);
            abort_unless(in_array($bookingSeries->approval_status, ['submitted', 'information_required'], true), 422);
            $bookingSeries->update(['status' => 'draft', 'approval_status' => 'withdrawn']);

            return response()->json($bookingSeries);
        }
        abort_unless(app(BookingAccessService::class)->canManage($request->user(), $bookingSeries->facility), 403);
        if ($data['action'] === 'begin_review') {
            $bookingSeries->update(['approval_status' => 'under_review']);
        }
        if ($data['action'] === 'request_information') {
            abort_unless($data['reason'] ?? null, 422, 'A reason is required.');
            $bookingSeries->update(['approval_status' => 'information_required', 'approval_reason' => $data['reason']]);
        }
        if ($data['action'] === 'reject') {
            abort_unless($data['reason'] ?? null, 422, 'A rejection reason is required.');
            $bookingSeries->update(['status' => 'rejected', 'approval_status' => 'rejected', 'rejection_reason' => $data['reason'], 'rejected_at' => now(), 'rejected_by_user_id' => $request->user()->id]);
        }
        if ($data['action'] === 'cancel') {
            $bookingSeries->update(['status' => 'cancelled', 'approval_status' => 'cancelled']);
        }
        $result = null;
        if ($data['action'] === 'approve') {
            abort_unless(in_array($bookingSeries->approval_status, ['submitted', 'under_review'], true), 422);
            $result = $materializer->materialize($request->user(), $bookingSeries, $data['occurrence_numbers'] ?? [], (bool) ($data['partial'] ?? false));
            $partial = collect($result['outcomes'])->contains(fn ($row) => $row['outcome'] !== 'created' && $row['outcome'] !== 'already_created');
            $bookingSeries->update(['status' => $partial ? 'partially_approved' : 'active', 'approval_status' => $partial ? 'partially_approved' : 'approved', 'approved_at' => now(), 'approved_by_user_id' => $request->user()->id]);
        }
        Audit::record("booking_series.{$data['action']}", $bookingSeries, [], ['reason' => $data['reason'] ?? null, 'result' => $result ? ['created' => $result['created']] : null]);

        return response()->json(['series' => $bookingSeries->fresh(), 'materialization' => $result]);
    }

    private function authorizeView(Request $request, BookingSeries $series): void
    {
        abort_unless($request->user()->member_id === $series->member_id || app(BookingAccessService::class)->canView($request->user(), $series->facility), 403);
    }

    private function authorizeOwner(Request $request, BookingSeries $series): void
    {
        abort_unless($request->user()->active && ($request->user()->member_id === $series->member_id || app(BookingAccessService::class)->canManage($request->user(), $series->facility)), 403);
    }

    private function definition(Request $request): array
    {
        return $request->validate([
            'facility_id' => 'required|exists:facilities,id', 'sport_code_id' => 'required|exists:sport_codes,id', 'member_id' => 'required|exists:members,id',
            'series_type' => 'required|in:member_recurring,coach_block,league_team,social_group,school_rental,club_programme,tournament,committee,other',
            'starts_on' => 'required|date|after_or_equal:today', 'ends_on' => 'nullable|date|after_or_equal:starts_on', 'start_time' => 'required|date_format:H:i',
            'duration_minutes' => 'required|integer|between:15,480', 'timezone' => 'required|timezone:all',
            'recurrence_frequency' => 'required|in:daily,weekly,monthly_fixed,monthly_ordinal', 'recurrence_interval' => 'required|integer|between:1,12',
            'days_of_week' => 'nullable|array|max:7', 'days_of_week.*' => 'integer|between:1,7', 'monthly_pattern' => 'nullable|array',
            'occurrence_limit' => 'nullable|integer|between:1,104', 'guest_count' => 'integer|between:0,20', 'participant_template' => 'nullable|array|max:12',
            'billing_strategy' => 'required|in:no_charge,allowance_covered,invoice_each_occurrence,one_series_invoice',
            'booking_type' => 'nullable|string|max:40', 'member_notes' => 'nullable|string|max:1000', 'administrator_notes' => 'nullable|string|max:1000',
            'idempotency_key' => 'required|uuid',
        ]);
    }
}
