<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\BookingRefundRequest;
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
            $checkout = PublicBookingCheckout::create(['checkout_reference' => (string) Str::uuid(), 'booking_hold_id' => $hold->id, 'public_visitor_id' => $visitor->id, 'provider' => app(PayFastService::class)->configured() ? 'payfast' : 'dry_run', 'status' => $result['price']['total_amount'] > 0 ? 'pending' : 'not_required', 'currency' => $result['price']['currency'], 'amount' => $result['price']['total_amount'], 'idempotency_key' => (string) Str::uuid(), 'expires_at' => $hold->expires_at]);
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
        if ($checkout->status === 'paid') {
            return ['status' => 'paid'];
        }
        if (app(PayFastService::class)->configured()) {
            return app(PayFastService::class)->checkout($checkout);
        }
        abort_unless(app()->environment(['local', 'testing']) && $setting->dry_run_checkout_enabled, 422, 'Online payment is not configured.');
        if (($data['outcome'] ?? 'success') === 'failure') {
            $checkout->update(['status' => 'failed', 'failed_at' => now()]);
            $this->event('checkout.failed', $visitor, null, $request, []);

            return ['status' => 'failed'];
        }
        $checkout->update(['status' => 'paid', 'paid_at' => now(), 'provider_reference' => 'DRY-'.Str::upper(Str::random(16))]);
        $this->event('checkout.paid', $visitor, null, $request, []);

        return ['status' => 'paid'];
    }

    public function checkoutStatus(PublicVisitor $visitor, BookingHold $hold): array
    {
        abort_unless($hold->public_visitor_id === $visitor->id, 404);
        $checkout = PublicBookingCheckout::where('booking_hold_id', $hold->id)->firstOrFail();
        return ['status' => $checkout->status, 'provider' => $checkout->provider, 'expires_at' => $checkout->expires_at];
    }

    public function completePayFastCheckout(PublicBookingCheckout $checkout, Request $request): void
    {
        DB::transaction(function () use ($checkout, $request) {
            $checkout = PublicBookingCheckout::lockForUpdate()->findOrFail($checkout->id);
            if ($checkout->status === 'paid') return;
            abort_unless($checkout->expires_at->isFuture() && $checkout->hold->status === 'active', 422, 'This payment hold has expired.');
            $checkout->update(['provider' => 'payfast', 'status' => 'paid', 'paid_at' => now(), 'provider_reference' => (string) $request->input('pf_payment_id')]);
            $this->event('checkout.paid', $checkout->visitor, null, $request, ['provider' => 'payfast']);
        });
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
                $payment = app(PaymentService::class)->record($actor, ['payment_reference' => 'PUB-'.Str::upper(Str::random(12)), 'member_id' => null, 'public_visitor_id' => $visitor->id, 'payer_name' => $visitor->display_name, 'currency' => $booking->currency, 'amount' => $amount, 'received_on' => now()->toDateString(), 'payment_method' => $checkout->provider === 'payfast' ? 'card' : 'other', 'external_reference' => $checkout->provider_reference, 'bank_reference' => null, 'import_fingerprint' => null, 'source' => 'public_'.$checkout->provider]);
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

    public function updateParticipants(string $raw, array $participants, Request $request): array
    {
        $token = $this->token($raw);
        $booking = $token->booking;
        abort_unless(in_array($booking->status, ['pending_payment', 'confirmed'], true), 422, 'Participants can no longer be changed.');
        abort_if(count($participants) > $this->setting($booking->sport_code_id)->maximum_participants, 422, 'Too many participants were supplied.');

        DB::transaction(function () use ($booking, $participants, $request) {
            $booking = Booking::lockForUpdate()->findOrFail($booking->id);
            $before = $booking->participants()->get()->map->only(['display_name', 'age_group', 'role'])->all();
            $booking->participants()->delete();
            foreach ($participants as $participant) {
                abort_if(($participant['age_group'] ?? 'adult') === 'junior' && ! ($participant['guardian_consent'] ?? false), 422, 'Guardian consent is required for junior participants.');
                $booking->participants()->create([
                    'participant_type' => 'visitor_guest',
                    'display_name' => $participant['name'],
                    'age_group' => $participant['age_group'] ?? 'adult',
                    'role' => $participant['role'] ?? 'guest',
                    'guardian_consent' => (bool) ($participant['guardian_consent'] ?? false),
                ]);
            }
            $booking->update(['guest_count' => count($participants)]);
            Audit::record('public_booking.participants_updated', $booking, ['participants' => $before], ['participant_count' => count($participants)]);
            $this->event('booking.participants_updated', $booking->publicVisitor, $booking, $request, ['participant_count' => count($participants)]);
        });

        return $this->managed($raw);
    }

    public function checkIn(string $raw, Request $request): array
    {
        $token = $this->token($raw);
        $booking = $token->booking;
        abort_unless($booking->status === 'confirmed' && in_array($booking->payment_status, ['paid', 'not_required'], true), 422, 'This booking cannot be checked in.');
        $attendance = app(BookingCheckInService::class)->ensure($booking);
        abort_if(now()->lt($attendance->check_in_opens_at), 422, 'Check-in has not opened.');
        abort_if(now()->gt($attendance->check_in_closes_at), 422, 'The check-in window has closed.');

        $attendance = app(BookingCheckInService::class)->checkIn(
            $this->systemActor(),
            $booking,
            'public_management',
            allowTemporaryAccess: $this->setting($booking->sport_code_id)->temporary_access_eligible,
        );
        $this->event('booking.checked_in', $booking->publicVisitor, $booking, $request, ['status' => $attendance->status]);

        return $this->managed($raw);
    }

    public function administration(User $user): array
    {
        abort_unless($user->active, 403);
        $codes = $user->isCentralAdministrator() ? null : $user->sportCodes()->pluck('sport_codes.id');
        $scope = fn ($query) => $query->when($codes !== null, fn ($q) => $q->whereIn('sport_code_id', $codes));
        $bookings = $scope(Booking::with(['facility', 'invoice', 'attendance', 'publicVisitor'])->whereNotNull('public_visitor_id'))->latest()->limit(100)->get();
        $visitors = PublicVisitor::when($codes !== null, fn ($query) => $query->whereHas('bookings', fn ($booking) => $booking->whereIn('sport_code_id', $codes)))->latest()->limit(100)->get();
        $failedCheckouts = PublicBookingCheckout::whereHas('hold', fn ($query) => $query->when($codes !== null, fn ($q) => $q->whereHas('facility', fn ($facility) => $facility->whereIn('sport_code_id', $codes))))->where('status', 'failed')->count();
        $refunds = BookingRefundRequest::whereHas('booking', fn ($query) => $query->whereNotNull('public_visitor_id')->when($codes !== null, fn ($q) => $q->whereIn('sport_code_id', $codes)))->whereNotIn('status', ['completed', 'rejected'])->count();

        return [
            'bookings' => $bookings->map(fn ($b) => $this->safeBooking($b) + ['visitor_reference' => $b->publicVisitor?->public_reference, 'visitor_name' => $b->publicVisitor?->display_name, 'verification_state' => $b->publicVisitor?->status, 'check_in_state' => $b->attendance?->status]),
            'visitors' => $visitors->map(fn ($visitor) => ['public_reference' => $visitor->public_reference, 'name' => $visitor->display_name, 'email' => $this->maskEmail($visitor->email), 'mobile' => $this->maskMobile($visitor->mobile), 'status' => $visitor->status, 'email_verified' => (bool) $visitor->email_verified_at, 'mobile_verified' => (bool) $visitor->mobile_verified_at, 'marketing_consent' => $visitor->marketing_consent]),
            'settings' => PublicBookingSetting::when($codes !== null, fn ($query) => $query->where(fn ($q) => $q->whereNull('sport_code_id')->orWhereIn('sport_code_id', $codes)))->get()->map->only(['id', 'sport_code_id', 'enabled', 'email_verification_required', 'mobile_verification_required', 'payment_required', 'temporary_access_eligible', 'hold_minutes', 'maximum_active_holds', 'maximum_future_bookings', 'maximum_participants']),
            'report' => ['bookings' => $bookings->count(), 'confirmed' => $bookings->where('status', 'confirmed')->count(), 'cancelled' => $bookings->where('status', 'cancelled')->count(), 'failed_checkout' => $failedCheckouts, 'pending_refunds' => $refunds, 'booked_minutes' => $bookings->sum('duration_minutes'), 'revenue' => $bookings->where('payment_status', 'paid')->sum('total_amount'), 'restricted_visitors' => $visitors->whereIn('status', ['restricted', 'blocked'])->count()],
            'authorization' => ['can_manage_global_visitors' => $user->isCentralAdministrator(), 'manageable_sport_codes' => $user->isCentralAdministrator() ? 'all' : $user->sportCodes()->whereIn('sport_code_user.role', ['administrator', 'committee'])->pluck('sport_codes.id')],
        ];
    }

    public function setVisitorStatus(User $user, PublicVisitor $visitor, string $status, string $reason): array
    {
        abort_unless($user->active && $user->isCentralAdministrator(), 403);
        abort_if($visitor->status === 'converted_to_member', 422, 'Converted visitors cannot be changed here.');
        $before = $visitor->status;
        $visitor->update(['status' => $status]);
        Audit::record('public_visitor.status_changed', $visitor, ['status' => $before], ['status' => $status, 'reason' => $reason]);

        return ['public_reference' => $visitor->public_reference, 'status' => $visitor->status];
    }

    public function updateSetting(User $user, PublicBookingSetting $setting, array $data): array
    {
        abort_unless($user->active, 403);
        abort_unless($setting->sport_code_id === null ? $user->isCentralAdministrator() : $user->canAccessSportCode($setting->sport_code_id, true), 403);
        abort_if(($data['dry_run_checkout_enabled'] ?? false) && ! app()->environment(['local', 'testing']), 422, 'Dry-run checkout is unavailable in production.');
        $before = $setting->only(array_keys($data));
        $setting->update($data);
        Audit::record('public_booking.setting_updated', $setting, $before, $setting->only(array_keys($data)));

        return $setting->fresh()->only(['id', 'sport_code_id', 'enabled', 'dry_run_checkout_enabled', 'email_verification_required', 'mobile_verification_required', 'payment_required', 'temporary_access_eligible', 'hold_minutes', 'maximum_active_holds', 'maximum_future_bookings', 'maximum_participants']);
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

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function maskMobile(string $mobile): string
    {
        return str_repeat('*', max(0, strlen($mobile) - 4)).substr($mobile, -4);
    }

    private function holdResponse(BookingHold $hold): array
    {
        $checkout = PublicBookingCheckout::where('booking_hold_id', $hold->id)->first();

        return ['hold_reference' => $hold->hold_reference, 'expires_at' => $hold->expires_at, 'price' => $hold->price_snapshot, 'lighting' => $hold->lighting_snapshot, 'cancellation' => $hold->cancellation_snapshot, 'checkout' => $checkout?->only(['checkout_reference', 'provider', 'status', 'currency', 'amount', 'expires_at'])];
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
