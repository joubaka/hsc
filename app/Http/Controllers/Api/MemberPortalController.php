<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CommunicationDelivery;
use App\Models\ChampionshipAnnouncement;
use App\Models\ChampionshipEntry;
use App\Models\ChampionshipMatch;
use App\Models\ChampionshipSchedulePublication;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SportMembership;
use App\Services\BookingFinancialCompensationService;
use App\Services\BookingRuleEvaluator;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MemberPortalController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = \App\Models\User::where('email', Str::lower($data['email']))->first();
        if (! $user || ! $user->active || ! $user->member_id || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'The supplied member credentials are invalid.'], 422);
        }
        $token = Str::random(80);
        $user->update(['api_token_hash' => hash('sha256', $token), 'last_login_at' => now()]);

        return response()->json(['token' => $token, 'member' => $this->memberPayload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->memberPayload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->update(['api_token_hash' => null]);
        return response()->json(['message' => 'Signed out.']);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $member = $request->user()->member;
        $upcoming = Booking::with(['facility.venue', 'attendance', 'lightingReservations'])
            ->where('member_id', $member->id)->whereIn('status', ['confirmed', 'pending_payment'])
            ->where('ends_at', '>=', now())->orderBy('starts_at')->limit(8)->get();
        $invoices = Invoice::with('lines')->where('member_id', $member->id)->latest('issued_on')->limit(10)->get();
        $entryIds = ChampionshipEntry::whereHas('members', fn ($query) => $query->where('members.id', $member->id))->pluck('id');
        $tournamentMatches = ChampionshipMatch::with([
            'draw.championshipEvent.championship', 'entry1.members', 'entry2.members', 'winner.members', 'facility',
        ])->where(fn ($query) => $query->whereIn('entry1_id', $entryIds)->orWhereIn('entry2_id', $entryIds))
            ->orderByRaw('scheduled_at is null, scheduled_at')->get();
        $championshipIds = $tournamentMatches->pluck('draw.championshipEvent.club_championship_id')->filter()->unique();
        $tournaments = [
            'entries' => ChampionshipEntry::with(['event.championship', 'members'])
                ->whereIn('id', $entryIds)->get(),
            'matches' => $tournamentMatches,
            'orders_of_play' => ChampionshipSchedulePublication::whereIn('club_championship_id', $championshipIds)
                ->where('is_current', true)->whereDate('play_date', '>=', today())->orderBy('play_date')->get(),
            'announcements' => ChampionshipAnnouncement::whereIn('club_championship_id', $championshipIds)
                ->whereJsonContains('channels', 'portal')->whereNotNull('published_at')->latest('published_at')->limit(20)->get(),
        ];

        return response()->json([
            'member' => $this->memberPayload($request->user()),
            'memberships' => SportMembership::with(['sportCode', 'category', 'period'])->where('member_id', $member->id)->latest('ends_on')->get(),
            'upcoming_bookings' => $upcoming,
            'recent_invoices' => $invoices,
            'outstanding_balance' => (int) Invoice::where('member_id', $member->id)->whereNotIn('status', ['draft', 'void'])->sum('outstanding_amount'),
            'access' => $member->accessCredential,
            'tournaments' => $tournaments,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferred_name' => ['nullable', 'string', 'max:100'], 'mobile' => ['nullable', 'string', 'max:30'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'], 'emergency_contact_mobile' => ['nullable', 'string', 'max:30'],
            'email_consent' => ['required', 'boolean'], 'whatsapp_consent' => ['required', 'boolean'],
        ]);
        $request->user()->member->update($data);
        return response()->json($this->memberPayload($request->user()->fresh()));
    }

    public function bookings(Request $request): JsonResponse
    {
        return response()->json(Booking::with(['facility.venue', 'invoice', 'attendance', 'lightingReservations'])
            ->where('member_id', $request->user()->member_id)->latest('starts_at')->paginate(30));
    }

    public function facilities(Request $request): JsonResponse
    {
        $sportIds = $request->user()->member->sportCodes()->wherePivot('status', 'active')->pluck('sport_codes.id');
        return response()->json(Facility::with(['venue', 'openingHours'])->where('active', true)->where('bookable', true)->whereIn('sport_code_id', $sportIds)->orderBy('display_order')->orderBy('name')->get());
    }

    public function schedule(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date']]);
        $dayStart = Carbon::parse($data['date'], 'Africa/Johannesburg')->startOfDay()->utc();
        $dayEnd = $dayStart->copy()->addDay();
        $sportIds = $request->user()->member->sportCodes()->wherePivot('status', 'active')->pluck('sport_codes.id');
        $facilityIds = Facility::where('active', true)->where('bookable', true)->whereIn('sport_code_id', $sportIds)->pluck('id');
        $memberId = $request->user()->member_id;
        $items = Booking::whereIn('facility_id', $facilityIds)->whereIn('status', ['confirmed', 'pending_payment'])
            ->where('ends_at', '>', $dayStart)->where('starts_at', '<', $dayEnd)
            ->orderBy('starts_at')->get()->map(fn (Booking $booking) => [
                'id' => $booking->member_id === $memberId ? $booking->id : null,
                'facility_id' => $booking->facility_id,
                'starts_at' => $booking->starts_at,
                'ends_at' => $booking->ends_at,
                'status' => $booking->member_id === $memberId ? $booking->status : 'unavailable',
                'is_mine' => $booking->member_id === $memberId,
                'label' => $booking->member_id === $memberId ? $booking->booking_reference : 'Booked',
            ]);

        return response()->json($items);
    }

    public function simulate(Request $request, BookingRuleEvaluator $evaluator): JsonResponse
    {
        $data = $this->slotData($request);
        $facility = Facility::findOrFail($data['facility_id']);
        $this->authorizeFacility($request, $facility);
        return response()->json($evaluator->evaluate($request->user(), $request->user()->member, $facility, Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']), $data['guest_count'] ?? 0, null, null, (bool) ($data['lighting_requested'] ?? false)));
    }

    public function hold(Request $request, BookingService $service): JsonResponse
    {
        $data = $this->slotData($request) + $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $facility = Facility::findOrFail($data['facility_id']);
        $this->authorizeFacility($request, $facility);
        $data['member_id'] = $request->user()->member_id;
        return response()->json($service->createHold($request->user(), $facility, $request->user()->member, $data), 201);
    }

    public function confirm(Request $request, BookingHold $hold, BookingService $service): JsonResponse
    {
        abort_unless($hold->member_id === $request->user()->member_id, 403);
        $data = $request->validate(['confirmation_key' => ['required', 'uuid'], 'participants' => ['array', 'max:12'], 'participants.*.participant_type' => ['required', Rule::in(['member', 'guest'])], 'participants.*.display_name' => ['required', 'string', 'max:100']]);
        return response()->json($service->confirm($request->user(), $hold, $data['confirmation_key'], $data['participants'] ?? [], 'invoice_each_occurrence'), 201);
    }

    public function cancel(Request $request, Booking $booking, BookingFinancialCompensationService $service): JsonResponse
    {
        abort_unless($booking->member_id === $request->user()->member_id, 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500'], 'idempotency_key' => ['required', 'uuid']]);
        return response()->json($service->cancel($request->user(), $booking, $data['reason'], $data['idempotency_key']));
    }

    public function finance(Request $request): JsonResponse
    {
        $id = $request->user()->member_id;
        return response()->json([
            'invoices' => Invoice::with(['lines', 'allocations.payment.receipt'])->where('member_id', $id)->latest('issued_on')->get(),
            'payments' => Payment::with('receipt')->where('member_id', $id)->latest('received_on')->get(),
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        return response()->json(CommunicationDelivery::with('campaign:id,type,name')->where('member_id', $request->user()->member_id)->whereIn('status', ['queued', 'sent'])->latest()->paginate(30));
    }

    public function readMessage(Request $request, CommunicationDelivery $delivery): JsonResponse
    {
        abort_unless($delivery->member_id === $request->user()->member_id, 403);
        $delivery->update(['read_at' => $delivery->read_at ?: now()]);
        return response()->json($delivery->fresh('campaign:id,type,name'));
    }

    private function memberPayload($user): array
    {
        $member = $user->member()->with(['household.members:id,household_id,member_number,first_name,preferred_name,last_name,status', 'sportCodes', 'accessCredential'])->firstOrFail();
        return ['id' => $member->id, 'member_number' => $member->member_number, 'display_name' => $member->display_name, 'first_name' => $member->first_name, 'preferred_name' => $member->preferred_name, 'last_name' => $member->last_name, 'email' => $member->email, 'mobile' => $member->mobile, 'status' => $member->status, 'emergency_contact_name' => $member->emergency_contact_name, 'emergency_contact_mobile' => $member->emergency_contact_mobile, 'email_consent' => $member->email_consent, 'whatsapp_consent' => $member->whatsapp_consent, 'household' => $member->household, 'sport_codes' => $member->sportCodes, 'access_credential' => $member->accessCredential];
    }

    private function slotData(Request $request): array
    {
        return $request->validate(['facility_id' => ['required', 'exists:facilities,id'], 'starts_at' => ['required', 'date', 'after:now'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'guest_count' => ['nullable', 'integer', 'between:0,20'], 'lighting_requested' => ['nullable', 'boolean']]);
    }

    private function authorizeFacility(Request $request, Facility $facility): void
    {
        abort_unless($request->user()->member->sportCodes()->wherePivot('status', 'active')->where('sport_codes.id', $facility->sport_code_id)->exists(), 403);
    }
}
