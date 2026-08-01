<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Facility;
use App\Models\OperationalNotification;
use App\Models\PublicBookingCheckout;
use App\Models\PublicBookingEvent;
use App\Models\PublicBookingManagementToken;
use App\Models\PublicBookingSetting;
use App\Models\PublicVisitor;
use App\Models\User;
use App\Models\VisitorVerificationChallenge;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicBookingService
{
    public function __construct(private BookingRuleEvaluator $rules, private BookingBillingService $billing) {}

    public function identify(array $data, Request $request): array
    {
        $email = Str::lower(trim($data['email']));
        $mobile = preg_replace('/\D+/', '', $data['mobile']);
        $visitor = PublicVisitor::where('email', $email)->where('mobile', $mobile)->first();
        if (! $visitor) {
            $visitor = PublicVisitor::create(['public_reference' => (string) Str::uuid(), 'first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name']), 'email' => $email, 'mobile' => $mobile, 'preferred_language' => $data['preferred_language'] ?? null, 'status' => 'pending_verification', 'marketing_consent' => (bool) ($data['marketing_consent'] ?? false)]);
        } else {
            abort_if(in_array($visitor->status, ['blocked', 'restricted', 'archived'], true), 422, 'This booking request cannot be completed online.');
            $visitor->update(['first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name']), 'marketing_consent' => (bool) ($data['marketing_consent'] ?? false)]);
        }
        $raw = Str::random(64);
        $visitor->update(['session_token_hash' => hash('sha256', $raw), 'session_expires_at' => now()->addHours(2)]);
        $this->event('visitor.identity_resolved', $visitor, null, $request, ['new' => $visitor->wasRecentlyCreated]);

        return ['visitor_reference' => $visitor->public_reference, 'session_token' => $raw, 'verification' => ['email' => (bool) $visitor->email_verified_at, 'mobile' => (bool) $visitor->mobile_verified_at]];
    }

    public function visitor(Request $request): PublicVisitor
    {
        $raw = (string) $request->header('X-Visitor-Token');
        $visitor = $raw ? PublicVisitor::where('session_token_hash', hash('sha256', $raw))->first() : null;
        abort_unless($visitor && $visitor->session_expires_at?->isFuture(), 404, 'This booking session is unavailable.');
        abort_if(in_array($visitor->status, ['blocked', 'restricted', 'archived'], true), 422, 'This booking request cannot be completed online.');

        return $visitor;
    }

    public function requestVerification(PublicVisitor $visitor, array $data, Request $request): array
    {
        $channel = $data['channel'];
        abort_if($channel === 'mobile', 422, 'Mobile verification is not configured.');
        VisitorVerificationChallenge::where('public_visitor_id', $visitor->id)->where('channel', $channel)->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $code = (string) random_int(100000, 999999);
        $challenge = VisitorVerificationChallenge::create(['public_visitor_id' => $visitor->id, 'channel' => $channel, 'purpose' => $data['purpose'] ?? 'new_booking_identity', 'token_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(10), 'maximum_attempts' => 5, 'idempotency_key' => $data['idempotency_key'], 'request_fingerprint' => $this->fingerprint($request)]);
        OperationalNotification::firstOrCreate(['event_type' => 'public.verification_requested', 'subject_type' => PublicVisitor::class, 'subject_id' => $visitor->id, 'user_id' => null], ['safe_payload' => ['channel' => $channel, 'delivery' => 'configured_provider_required']]);
        $this->event('verification.requested', $visitor, null, $request, ['channel' => $channel]);
        $response = ['outcome' => 'If the contact information can be verified, instructions have been issued.', 'expires_at' => $challenge->expires_at];
        if (app()->environment(['local', 'testing']) && $this->setting(null)->dry_run_checkout_enabled) {
            $response['development_code'] = $code;
        }

        return $response;
    }

    public function verify(PublicVisitor $visitor, array $data, Request $request): array
    {
        $challenge = VisitorVerificationChallenge::where('public_visitor_id', $visitor->id)->where('channel', $data['channel'])->whereNull('used_at')->whereNull('revoked_at')->latest()->first();
        abort_unless($challenge && $challenge->expires_at->isFuture() && $challenge->attempt_count < $challenge->maximum_attempts, 422, 'The verification code is invalid or expired.');
        $challenge->increment('attempt_count');
        abort_unless(hash_equals($challenge->token_hash, hash('sha256', $data['code'])), 422, 'The verification code is invalid or expired.');
        $challenge->update(['used_at' => now()]);
        $field = $data['channel'] === 'email' ? 'email_verified_at' : 'mobile_verified_at';
        $visitor->update([$field => now(), 'status' => 'verified']);
        $this->event('verification.completed', $visitor, null, $request, ['channel' => $data['channel']]);

        return ['verified' => true, 'channel' => $data['channel']];
    }

    public function preview(PublicVisitor $visitor, Facility $facility, array $data, ?int $ignoreHoldId = null): array
    {
        $setting = $this->setting($facility->sport_code_id);
        abort_unless($setting->enabled && $facility->publicly_visible && $facility->bookable && $facility->active, 404, 'This court is unavailable for public booking.');
        abort_if($setting->email_verification_required && ! $visitor->email_verified_at, 422, 'Email verification is required.');
        $profile = ['profile' => 'public_visitor', 'member_id' => null, 'membership_category_id' => null, 'sport_membership_id' => null, 'age' => null, 'failures' => [], 'eligible' => true];
        $result = $this->rules->evaluate($this->systemActor(), null, $facility, Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']), count($data['participants'] ?? []), $ignoreHoldId, null, (bool) ($data['lighting_requested'] ?? false), $profile);
        abort_unless($result['eligible'], 422, $result['failures'][0]['member_message'] ?? 'This booking is unavailable.');
        $future = Booking::where('public_visitor_id', $visitor->id)->whereIn('status', ['pending_payment', 'confirmed'])->where('starts_at', '>', now())->count();
        abort_if($future >= $setting->maximum_future_bookings, 422, 'The public booking limit has been reached.');
        abort_if(count($data['participants'] ?? []) > $setting->maximum_participants, 422, 'Too many participants were supplied.');
        $result['cancellation']['refund_method'] = 'refund';

        return $result;
    }

    public function hold(PublicVisitor $visitor, Facility $facility, array $data, Request $request): array
    {
        if ($existing = BookingHold::where('idempotency_key', $data['idempotency_key'])->where('public_visitor_id', $visitor->id)->first()) {
            return $this->holdResponse($existing);
        }

        return DB::transaction(function () use ($visitor, $facility, $data, $request) {
            $facility = Facility::lockForUpdate()->findOrFail($facility->id);
            $setting = $this->setting($facility->sport_code_id);
            $active = BookingHold::where('public_visitor_id', $visitor->id)->where('status', 'active')->where('expires_at', '>', now())->count();
            abort_if($active >= $setting->maximum_active_holds, 422, 'The active hold limit has been reached.');
            $result = $this->preview($visitor, $facility, $data);
            // Normalize both boundaries before persistence. MySQL datetime columns do not
            // retain an incoming offset, so storing one raw ISO value and one UTC value can
            // invert the interval when the public browser sends an explicit timezone.
            $startsAt = Carbon::parse($data['starts_at'])->utc();
            $endsAt = Carbon::parse($data['ends_at'])->utc();
            $hold = BookingHold::create(['hold_reference' => (string) Str::uuid(), 'facility_id' => $facility->id, 'user_id' => $this->systemActor()->id, 'member_id' => null, 'public_visitor_id' => $visitor->id, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'expires_at' => now()->addMinutes($setting->hold_minutes), 'status' => 'active', 'rule_snapshot' => ['profile' => $result['profile'], 'policies' => $result['policies'], 'checks' => $result['checks']], 'price_snapshot' => $result['price'], 'allowance_snapshot' => $result['allowance'], 'cancellation_snapshot' => $result['cancellation'], 'lighting_snapshot' => $result['lighting'], 'public_consent_snapshot' => ['terms_version' => $setting->terms_version, 'privacy_version' => $setting->privacy_version, 'facility_rules_version' => $setting->facility_rules_version, 'accepted_at' => now()->toIso8601String(), 'marketing_consent' => $visitor->marketing_consent], 'idempotency_key' => $data['idempotency_key']]);
            $visitor->update(['terms_accepted_at' => now(), 'privacy_accepted_at' => now()]);
            $checkout = PublicBookingCheckout::create(['checkout_reference' => (string) Str::uuid(), 'booking_hold_id' => $hold->id, 'public_visitor_id' => $visitor->id, 'provider' => 'dry_run', 'status' => $result['price']['total_amount'] > 0 ? 'pending' : 'not_required', 'currency' => $result['price']['currency'], 'amount' => $result['price']['total_amount'], 'idempotency_key' => (string) Str::uuid(), 'expires_at' => $hold->expires_at]);
            Audit::record('public_booking.hold_created', $hold, [], ['facility_id' => $facility->id, 'amount' => $checkout->amount]);
            $this->event('hold.created', $visitor, null, $request, ['facility_id' => $facility->id]);

            return $this->holdResponse($hold);
        }, 3);
    }

    public function pay(PublicVisitor $visitor, BookingHold $hold, array $data, Request $request): array
    {
        abort_unless($hold->public_visitor_id === $visitor->id && $hold->status === 'active' && $hold->expires_at->isFuture(), 404, 'This checkout is unavailable.');
        $checkout = PublicBookingCheckout::where('booking_hold_id', $hold->id)->firstOrFail();
        $setting = $this->setting($hold->facility->sport_code_id);
        abort_unless(app()->environment(['local', 'testing']) && $setting->dry_run_checkout_enabled, 422, 'Online payment is not configured.');
        if ($checkout->status === 'paid') {
            return ['status' => 'paid'];
        }
        if (($data['outcome'] ?? 'success') === 'failure') {
            $checkout->update(['status' => 'failed', 'failed_at' => now()]);
            $this->event('checkout.failed', $visitor, null, $request, []);

            return ['status' => 'failed'];
        }
        $checkout->update(['status' => 'paid', 'paid_at' => now(), 'provider_reference' => 'DRY-'.Str::upper(Str::random(16))]);
        $this->event('checkout.paid', $visitor, null, $request, []);

        return ['status' => 'paid'];
    }

    public function confirm(PublicVisitor $visitor, BookingHold $hold, array $data, Request $request): array
    {
        if ($existing = Booking::where('confirmation_key', $data['idempotency_key'])->orWhere('booking_hold_id', $hold->id)->first()) {
            return ['booking' => $this->safeBooking($existing), 'outcome' => 'already_confirmed'];
        }

        return DB::transaction(function () use ($visitor, $hold, $data, $request) {
            $hold = BookingHold::lockForUpdate()->findOrFail($hold->id);
            abort_unless($hold->public_visitor_id === $visitor->id && $hold->status === 'active' && $hold->expires_at->isFuture(), 404, 'This checkout is unavailable.');
            abort_unless($visitor->email_verified_at && $visitor->status === 'verified', 422, 'Verification is required.');
            $checkout = PublicBookingCheckout::where('booking_hold_id', $hold->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($checkout->status, ['paid', 'not_required'], true), 422, 'Payment is required.');
            $result = $this->preview($visitor, $hold->facility, ['starts_at' => $hold->starts_at, 'ends_at' => $hold->ends_at, 'participants' => $data['participants'] ?? [], 'lighting_requested' => (bool) ($hold->lighting_snapshot['applies'] ?? false)], $hold->id);
            abort_unless($result['price']['total_amount'] === (int) $hold->price_snapshot['total_amount'], 422, 'The booking price changed. Please create a new hold.');
            $actor = $this->systemActor();
            $amount = (int) $result['price']['total_amount'];
            $booking = Booking::create(['booking_reference' => 'BKG-'.Str::upper(Str::random(10)), 'facility_id' => $hold->facility_id, 'sport_code_id' => $hold->facility->sport_code_id, 'member_id' => null, 'public_visitor_id' => $visitor->id, 'created_by_user_id' => $actor->id, 'booking_hold_id' => $hold->id, 'status' => $amount ? 'pending_payment' : 'confirmed', 'payment_status' => $amount ? 'unpaid' : 'not_required', 'starts_at' => $hold->starts_at, 'ends_at' => $hold->ends_at, 'duration_minutes' => $hold->starts_at->diffInMinutes($hold->ends_at), 'guest_count' => count($data['participants'] ?? []), 'profile' => 'public_visitor', 'currency' => $result['price']['currency'], 'gross_amount' => $result['price']['gross_amount'], 'allowance_amount' => 0, 'total_amount' => $amount, 'rule_snapshot' => $hold->rule_snapshot, 'price_snapshot' => $result['price'], 'allowance_snapshot' => ['minutes' => 0, 'value_cents' => 0], 'cancellation_snapshot' => $hold->cancellation_snapshot, 'lighting_snapshot' => $hold->lighting_snapshot, 'confirmation_key' => $data['idempotency_key']]);
            foreach ($data['participants'] ?? [] as $participant) {
                $booking->participants()->create(['participant_type' => 'visitor_guest', 'display_name' => $participant['name'], 'age_group' => $participant['age_group'] ?? 'adult', 'role' => $participant['role'] ?? 'guest', 'guardian_consent' => (bool) ($participant['guardian_consent'] ?? false)]);
            }
            $hold->update(['status' => 'confirmed']);
            $invoice = $this->billing->createInvoice($actor, $booking);
            if ($amount > 0) {
                $payment = app(PaymentService::class)->record($actor, ['payment_reference' => 'PUB-'.Str::upper(Str::random(12)), 'member_id' => null, 'public_visitor_id' => $visitor->id, 'payer_name' => $visitor->display_name, 'currency' => $booking->currency, 'amount' => $amount, 'received_on' => now()->toDateString(), 'payment_method' => 'dry_run', 'external_reference' => $checkout->provider_reference, 'bank_reference' => null, 'import_fingerprint' => null, 'source' => 'public_dry_run']);
                $payment = app(PaymentService::class)->confirm($actor, $payment);
                app(PaymentService::class)->allocate($actor, $payment, $invoice, $amount, 'public-checkout:'.$checkout->id);
            }
            if ($hold->lighting_snapshot['applies'] ?? false) {
                app(LightingReservationService::class)->reserve($booking, $hold->lighting_snapshot);
            }
            app(BookingCheckInService::class)->ensure($booking);
            $visitor->update(['last_booking_at' => now()]);
            [$token, $raw] = $this->newManagementToken($booking, $visitor);
            Audit::record('public_booking.confirmed', $booking, [], ['facility_id' => $booking->facility_id, 'amount' => $booking->total_amount]);
            $this->event('booking.confirmed', $visitor, $booking, $request, []);

            return ['booking' => $this->safeBooking($booking->fresh()), 'management_path' => '/manage-booking/'.$raw, 'management_expires_at' => $token->expires_at, 'outcome' => 'confirmed'];
        }, 3);
    }

    public function managed(string $raw): array
    {
        $token = PublicBookingManagementToken::where('token_hash', hash('sha256', $raw))->whereNull('revoked_at')->first();
        abort_unless($token && $token->expires_at->isFuture(), 404, 'This booking link is unavailable.');
        $token->increment('use_count');
        $token->update(['last_used_at' => now()]);
        $booking = $token->booking->load(['facility', 'invoice.allocations.payment.receipt', 'participants', 'lightingReservations', 'attendance', 'accessGrants']);

        return $this->safeBooking($booking) + ['participants' => $booking->participants->map(fn ($p) => $p->only(['display_name', 'age_group', 'role'])), 'receipt_number' => $booking->invoice?->allocations->first()?->payment?->receipt?->receipt_number, 'lighting_status' => $booking->lightingReservations->first()?->status, 'check_in' => $booking->attendance?->only(['status', 'check_in_opens_at', 'check_in_closes_at']), 'temporary_access' => $booking->accessGrants->map->only(['status', 'valid_from', 'valid_until'])];
    }

    public function rotate(string $raw): array
    {
        $token = $this->token($raw);
        $token->update(['revoked_at' => now()]);
        [$new, $fresh] = $this->newManagementToken($token->booking, PublicVisitor::findOrFail($token->public_visitor_id));

        return ['management_path' => '/manage-booking/'.$fresh, 'expires_at' => $new->expires_at];
    }

    public function cancel(string $raw, string $reason): array
    {
        $token = $this->token($raw);
        $booking = $token->booking;
        $result = app(BookingFinancialCompensationService::class)->cancel($this->systemActor(), $booking, $reason, 'public-cancel:'.$booking->id, 'public_management');
        PublicBookingManagementToken::where('booking_id', $booking->id)->update(['revoked_at' => now()]);

        return ['status' => 'cancelled', 'outcome' => $result->outcome_type, 'refund_status' => $result->refundRequest?->status];
    }

    public function administration(User $user): array
    {
        abort_unless($user->active, 403);
        $codes = $user->isCentralAdministrator() ? null : $user->sportCodes()->pluck('sport_codes.id');
        $bookings = Booking::with(['facility', 'invoice', 'attendance'])->whereNotNull('public_visitor_id')->when($codes !== null, fn ($q) => $q->whereIn('sport_code_id', $codes))->latest()->limit(100)->get();

        return ['bookings' => $bookings->map(fn ($b) => $this->safeBooking($b) + ['visitor_reference' => $b->publicVisitor?->public_reference, 'verification_state' => $b->publicVisitor?->status]), 'report' => ['bookings' => $bookings->count(), 'confirmed' => $bookings->where('status', 'confirmed')->count(), 'cancelled' => $bookings->where('status', 'cancelled')->count(), 'revenue' => $bookings->sum('total_amount')]];
    }

    private function setting(?int $sportCodeId): PublicBookingSetting
    {
        return PublicBookingSetting::where('sport_code_id', $sportCodeId)->first() ?? PublicBookingSetting::firstOrCreate(['sport_code_id' => null], ['enabled' => app()->environment(['local', 'testing']), 'dry_run_checkout_enabled' => app()->environment(['local', 'testing'])]);
    }

    private function systemActor(): User
    {
        return User::where('protected_account', true)->where('active', true)->firstOrFail();
    }

    private function fingerprint(Request $request): string
    {
        return hash_hmac('sha256', (string) $request->ip(), config('app.key'));
    }

    private function holdResponse(BookingHold $hold): array
    {
        $checkout = PublicBookingCheckout::where('booking_hold_id', $hold->id)->first();

        return ['hold_reference' => $hold->hold_reference, 'expires_at' => $hold->expires_at, 'price' => $hold->price_snapshot, 'lighting' => $hold->lighting_snapshot, 'cancellation' => $hold->cancellation_snapshot, 'checkout' => $checkout?->only(['checkout_reference', 'status', 'currency', 'amount', 'expires_at'])];
    }

    private function token(string $raw): PublicBookingManagementToken
    {
        $token = PublicBookingManagementToken::where('token_hash', hash('sha256', $raw))->whereNull('revoked_at')->first();
        abort_unless($token && $token->expires_at->isFuture(), 404, 'This booking link is unavailable.');

        return $token;
    }

    private function newManagementToken(Booking $booking, PublicVisitor $visitor): array
    {
        $raw = Str::random(64);
        $token = PublicBookingManagementToken::create(['booking_id' => $booking->id, 'public_visitor_id' => $visitor->id, 'token_hash' => hash('sha256', $raw), 'token_prefix' => substr($raw, 0, 8), 'purpose' => 'manage', 'issued_at' => now(), 'expires_at' => max(now()->addDays(30), $booking->ends_at->copy()->addDays(7)), 'idempotency_key' => (string) Str::uuid()]);

        return [$token, $raw];
    }

    private function safeBooking(Booking $b): array
    {
        return ['booking_reference' => $b->booking_reference, 'facility' => $b->facility?->only(['name']), 'sport_code_id' => $b->sport_code_id, 'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at, 'status' => $b->status, 'payment_status' => $b->payment_status, 'currency' => $b->currency, 'amount' => $b->total_amount, 'cancellation_deadline' => $b->cancellation_snapshot['cancellation_deadline'] ?? null];
    }

    private function event(string $type, PublicVisitor $visitor, ?Booking $booking, Request $request, array $payload): void
    {
        PublicBookingEvent::create(['public_visitor_id' => $visitor->id, 'booking_id' => $booking?->id, 'sport_code_id' => $booking?->sport_code_id, 'event_type' => $type, 'safe_payload' => $payload, 'request_fingerprint' => $this->fingerprint($request)]);
    }
}
