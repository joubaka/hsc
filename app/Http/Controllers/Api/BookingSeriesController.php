<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\BookingSeriesOccurrence;
use App\Models\Facility;
use App\Models\Invoice;
use App\Services\BookingAccessService;
use App\Services\BookingOccurrenceAmendmentService;
use App\Services\BookingSeriesBulkMoveService;
use App\Services\BookingSeriesCancellationService;
use App\Services\BookingSeriesMaterializationService;
use App\Services\BookingSeriesPreviewService;
use App\Services\BookingSeriesRevisionService;
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
        abort_unless((! empty($data['member_id']) && $request->user()->member_id === (int) $data['member_id']) || app(BookingAccessService::class)->canManage($request->user(), $facility), 403);
        abort_if(empty($data['member_id']) && $data['billing_strategy'] !== 'no_charge', 422, 'Custom-name bulk bookings must use no charge because there is no member account to invoice.');
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

        return response()->json($bookingSeries->load(['facility', 'member', 'occurrences.booking.invoice', 'occurrences.booking.lightingReservations.zone']));
    }

    public function update(Request $request, BookingSeries $bookingSeries): JsonResponse
    {
        $this->authorizeOwner($request, $bookingSeries);
        abort_unless($bookingSeries->status === 'draft' && ! $bookingSeries->occurrences()->whereNotNull('booking_id')->exists(), 422, 'Only an unmaterialized draft can be edited directly.');
        $data = $this->definition($request);
        unset($data['idempotency_key']);
        $before = $bookingSeries->only(array_keys($data));
        $bookingSeries->update([...$data, 'version' => $bookingSeries->version + 1]);
        Audit::record('booking_series.draft_updated', $bookingSeries, $before, $bookingSeries->only(array_keys($data)));

        return response()->json($bookingSeries->fresh());
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

    public function amendOccurrence(Request $request, BookingSeriesOccurrence $occurrence, BookingOccurrenceAmendmentService $service): JsonResponse
    {
        $data = $request->validate([
            'facility_id' => 'nullable|exists:facilities,id', 'starts_at' => 'nullable|date|after:now',
            'ends_at' => 'nullable|date|after:starts_at', 'duration_minutes' => 'nullable|integer|between:15,480',
            'guest_count' => 'nullable|integer|between:0,20', 'notes' => 'nullable|string|max:1000',
            'participants' => 'nullable|array|max:12', 'participants.*.member_id' => 'nullable|exists:members,id',
            'participants.*.participant_type' => 'required_with:participants|in:member,guest',
            'participants.*.display_name' => 'required_with:participants|string|max:100',
            'reason' => 'nullable|string|max:500', 'idempotency_key' => 'required|uuid', 'series_version' => 'required|integer|min:1',
        ]);
        abort_unless((int) $occurrence->series_version === (int) $data['series_version'], 409, 'The series version changed. Refresh before continuing.');

        return response()->json($service->amend($request->user(), $occurrence, collect($data)->except(['reason', 'idempotency_key', 'series_version'])->all(), $data['idempotency_key'], $data['reason'] ?? null));
    }

    public function moveOccurrence(Request $request, BookingSeriesOccurrence $occurrence, BookingOccurrenceAmendmentService $service): JsonResponse
    {
        abort_unless(app(BookingAccessService::class)->canManage($request->user(), $occurrence->series->facility), 403);
        $data = $request->validate([
            'facility_id' => 'required|exists:facilities,id', 'starts_at' => 'nullable|date|after:now',
            'reason' => 'required|string|min:3|max:500', 'idempotency_key' => 'required|uuid', 'series_version' => 'required|integer|min:1',
        ]);
        abort_unless((int) $occurrence->series_version === (int) $data['series_version'], 409, 'The series version changed. Refresh before continuing.');

        return response()->json($service->amend($request->user(), $occurrence, collect($data)->except(['reason', 'idempotency_key', 'series_version'])->all(), $data['idempotency_key'], $data['reason']));
    }

    public function cancelScope(Request $request, BookingSeries $bookingSeries, BookingSeriesCancellationService $service): JsonResponse
    {
        $data = $request->validate([
            'scope' => 'required|in:occurrence,this_and_future,all_future,entire_unstarted',
            'effective_occurrence_id' => 'nullable|exists:booking_series_occurrences,id',
            'reason' => 'required|string|min:3|max:500', 'idempotency_key' => 'required|uuid',
            'series_version' => 'required|integer|min:1', 'all_or_nothing' => 'boolean',
        ]);
        abort_unless((int) $bookingSeries->version === (int) $data['series_version'], 409, 'The series version changed. Refresh before continuing.');
        if ($data['scope'] === 'entire_unstarted') {
            abort_if($bookingSeries->occurrences()->whereHas('booking', fn ($booking) => $booking->where('starts_at', '<=', now()))->exists(), 422, 'The series has historical occurrences.');
        }

        return response()->json($service->cancel($request->user(), $bookingSeries, $data['scope'], $data['effective_occurrence_id'] ?? null, $data['reason'], $data['idempotency_key'], (bool) ($data['all_or_nothing'] ?? false)));
    }

    public function reviseFuture(Request $request, BookingSeries $bookingSeries, BookingSeriesRevisionService $service): JsonResponse
    {
        $data = $request->validate([
            'effective_occurrence_id' => 'required|exists:booking_series_occurrences,id',
            'changes' => 'required|array',
            'changes.facility_id' => 'sometimes|exists:facilities,id',
            'changes.starts_on' => 'sometimes|date|after_or_equal:today',
            'changes.ends_on' => 'nullable|date|after_or_equal:changes.starts_on',
            'changes.start_time' => 'sometimes|date_format:H:i',
            'changes.duration_minutes' => 'sometimes|integer|between:15,480',
            'changes.recurrence_frequency' => 'sometimes|in:daily,weekly,monthly_fixed,monthly_ordinal',
            'changes.recurrence_interval' => 'sometimes|integer|between:1,12',
            'changes.days_of_week' => 'sometimes|array|max:7',
            'changes.days_of_week.*' => 'integer|between:1,7',
            'changes.monthly_pattern' => 'sometimes|array',
            'changes.occurrence_limit' => 'sometimes|integer|between:1,104',
            'changes.guest_count' => 'sometimes|integer|between:0,20',
            'changes.participant_template' => 'sometimes|array|max:12',
            'changes.billing_strategy' => 'sometimes|in:no_charge,allowance_covered,invoice_each_occurrence,one_invoice_for_series',
            'selected_occurrence_ids' => 'sometimes|array|max:104',
            'selected_occurrence_ids.*' => 'integer|exists:booking_series_occurrences,id',
            'reason' => 'required|string|min:3|max:500',
            'idempotency_key' => 'required|uuid',
            'series_version' => 'required|integer|min:1',
        ]);
        abort_unless((int) $bookingSeries->version === (int) $data['series_version'], 409, 'The series version changed. Refresh before continuing.');
        $effective = $bookingSeries->occurrences()->findOrFail($data['effective_occurrence_id']);

        return response()->json($service->revise($request->user(), $bookingSeries, $effective, $data['changes'], $data['reason'], $data['idempotency_key'], $data['selected_occurrence_ids'] ?? []));
    }

    public function moveScope(Request $request, BookingSeries $bookingSeries, BookingSeriesBulkMoveService $service): JsonResponse
    {
        $data = $request->validate([
            'facility_id' => 'required|exists:facilities,id',
            'scope' => 'required|in:occurrence,selected,this_and_future,all_future',
            'occurrence_ids' => 'sometimes|array|max:104',
            'occurrence_ids.*' => 'integer|exists:booking_series_occurrences,id',
            'effective_occurrence_id' => 'nullable|exists:booking_series_occurrences,id',
            'reason' => 'required|string|min:3|max:500',
            'idempotency_key' => 'required|uuid',
            'series_version' => 'required|integer|min:1',
            'partial' => 'boolean',
        ]);
        abort_unless((int) $bookingSeries->version === (int) $data['series_version'], 409, 'The series version changed. Refresh before continuing.');

        return response()->json($service->move(
            $request->user(), $bookingSeries, $data['facility_id'], $data['scope'],
            $data['occurrence_ids'] ?? [], $data['effective_occurrence_id'] ?? null,
            $data['reason'], $data['idempotency_key'], (bool) ($data['partial'] ?? false),
        ));
    }

    public function financialSummary(Request $request, BookingSeries $bookingSeries): JsonResponse
    {
        $this->authorizeView($request, $bookingSeries);
        $bookingIds = $bookingSeries->occurrences()->whereNotNull('booking_id')->pluck('booking_id');
        $invoiceIds = Booking::whereIn('id', $bookingIds)->whereNotNull('invoice_id')->pluck('invoice_id');
        $invoices = Invoice::with('lines:id,invoice_id,source_type,source_id,description,total_amount,financial_owner_type,sport_code_id')
            ->where(fn ($query) => $query->where(['source_type' => BookingSeries::class, 'source_id' => $bookingSeries->id])->orWhereIn('id', $invoiceIds))
            ->get();

        return response()->json([
            'currency' => 'ZAR',
            'invoice_count' => $invoices->count(),
            'invoiced_amount' => $invoices->sum('total_amount'),
            'paid_amount' => $invoices->sum('paid_amount'),
            'credited_amount' => $invoices->sum('credited_amount'),
            'outstanding_amount' => $invoices->sum('outstanding_amount'),
            'invoices' => $invoices,
        ]);
    }

    public function auditHistory(Request $request, BookingSeries $bookingSeries): JsonResponse
    {
        $this->authorizeView($request, $bookingSeries);
        $occurrenceIds = $bookingSeries->occurrences()->pluck('id');
        $rows = AuditLog::with('user:id,name')
            ->where(fn ($query) => $query
                ->where(fn ($series) => $series->where('auditable_type', BookingSeries::class)->where('auditable_id', $bookingSeries->id))
                ->orWhere(fn ($occurrences) => $occurrences->where('auditable_type', BookingSeriesOccurrence::class)->whereIn('auditable_id', $occurrenceIds)))
            ->latest()->limit(200)->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'event' => $row->action,
                'actor' => $row->user?->name ?? 'System',
                'occurred_at' => $row->created_at,
                'occurrence_id' => $row->auditable_type === BookingSeriesOccurrence::class ? $row->auditable_id : null,
                'reason' => data_get($row->new_values, 'reason'),
                'summary' => collect($row->new_values)->only(['version', 'status', 'outcome', 'amount', 'allowance_restored', 'financial_outcome']),
            ]);

        return response()->json($rows);
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
            'facility_id' => 'required|exists:facilities,id', 'sport_code_id' => 'required|exists:sport_codes,id',
            'member_id' => 'nullable|required_without:booked_for_name|exists:members,id', 'booked_for_name' => 'nullable|required_without:member_id|string|min:2|max:120',
            'series_type' => 'required|in:member_recurring,coach_block,league_team,social_group,school_rental,club_programme,tournament,committee,other',
            'starts_on' => 'required|date|after_or_equal:today', 'ends_on' => 'nullable|date|after_or_equal:starts_on', 'start_time' => 'required|date_format:H:i',
            'duration_minutes' => 'required|integer|between:15,480', 'timezone' => 'required|timezone:all',
            'recurrence_frequency' => 'required|in:daily,weekly,monthly_fixed,monthly_ordinal', 'recurrence_interval' => 'required|integer|between:1,12',
            'days_of_week' => 'nullable|array|max:7', 'days_of_week.*' => 'integer|between:1,7', 'monthly_pattern' => 'nullable|array',
            'occurrence_limit' => 'nullable|integer|between:1,104', 'guest_count' => 'integer|between:0,20', 'participant_template' => 'nullable|array|max:12',
            'billing_strategy' => 'required|in:no_charge,allowance_covered,invoice_each_occurrence,one_invoice_for_series',
            'lighting_requested' => 'boolean',
            'booking_type' => 'nullable|string|max:40', 'member_notes' => 'nullable|string|max:1000', 'administrator_notes' => 'nullable|string|max:1000',
            'idempotency_key' => 'required|uuid',
        ]);
    }
}
