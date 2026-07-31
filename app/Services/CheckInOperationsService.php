<?php

namespace App\Services;

use App\Models\AccessAlert;
use App\Models\AccessCommand;
use App\Models\AccessEmergencyAction;
use App\Models\AttendanceCorrection;
use App\Models\Booking;
use App\Models\BookingAttendance;
use App\Models\Facility;
use App\Models\FacilityCheckInToken;
use App\Models\OperationalNotification;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckInOperationsService
{
    public function rotateFacilityToken(User $actor, Facility $facility): array
    {
        abort_unless(app(BookingAccessService::class)->canManage($actor, $facility), 403);
        $raw = Str::random(64);
        $old = FacilityCheckInToken::where('facility_id', $facility->id)->first();
        $token = FacilityCheckInToken::updateOrCreate(['facility_id' => $facility->id], ['token_hash' => hash('sha256', $raw), 'token_prefix' => substr($raw, 0, 8), 'issued_at' => now(), 'rotated_at' => $old ? now() : null, 'revoked_at' => null]);
        Audit::record('facility.check_in_token_rotated', $facility, [], ['configured' => true]);

        return ['token' => $raw, 'path' => '/facility-check-in/'.$raw, 'configured' => true, 'token_prefix' => $token->token_prefix];
    }

    public function revokeFacilityToken(User $actor, Facility $facility): void
    {
        abort_unless(app(BookingAccessService::class)->canManage($actor, $facility), 403);
        FacilityCheckInToken::where('facility_id', $facility->id)->update(['revoked_at' => now()]);
        Audit::record('facility.check_in_token_revoked', $facility, [], ['configured' => false]);
    }

    public function facilityCandidates(User $user, string $raw): array
    {
        $token = FacilityCheckInToken::where('token_hash', hash('sha256', $raw))->whereNull('revoked_at')->first();
        abort_unless($token, 404, 'This check-in point is unavailable.');
        abort_unless($user->member_id, 422, 'No eligible booking was found.');

        return Booking::where('facility_id', $token->facility_id)->where('member_id', $user->member_id)->where('status', 'confirmed')->whereBetween('starts_at', [now()->subHours(2), now()->addHours(2)])->get()->filter(fn ($b) => app(BookingCheckInPolicyResolver::class)->resolve($b, $user)['check_in_allowed'])->map(fn ($b) => ['id' => $b->id, 'booking_reference' => $b->booking_reference, 'starts_at' => $b->starts_at, 'attendance_status' => app(BookingCheckInService::class)->ensure($b)->status])->values()->all();
    }

    public function facilityCheckIn(User $user, string $raw, int $bookingId): BookingAttendance
    {
        $c = collect($this->facilityCandidates($user, $raw));
        abort_unless($c->contains('id', $bookingId), 422, 'No eligible booking was found.');

        return app(BookingCheckInService::class)->checkIn($user, Booking::findOrFail($bookingId), 'facility_qr');
    }

    public function correct(User $actor, BookingAttendance $attendance, array $data): BookingAttendance
    {
        abort_unless(app(BookingAccessService::class)->canManage($actor, $attendance->booking->facility), 403);
        if ($existing = AttendanceCorrection::where('idempotency_key', $data['idempotency_key'])->first()) {
            return $existing->attendance;
        }

        return DB::transaction(function () use ($actor, $attendance, $data) {
            $attendance = BookingAttendance::lockForUpdate()->findOrFail($attendance->id);
            $before = $attendance->only(['status', 'checked_in_at', 'check_in_method', 'no_show_marked_at', 'released_at']);
            $a = $data['action'];
            $updates = match ($a) {
                'reverse_check_in' => ['status' => 'open', 'checked_in_at' => null, 'checked_in_by_user_id' => null, 'check_in_method' => null],'mark_late' => ['status' => 'late_checked_in'],'mark_no_show' => ['status' => 'no_show', 'no_show_marked_at' => now(), 'no_show_evaluated_at' => now()],'reverse_no_show' => ['status' => 'open', 'no_show_marked_at' => null, 'no_show_evaluated_at' => null],'excuse_absence' => ['status' => 'excused'],'release_court' => ['status' => 'released', 'released_at' => now(), 'release_reason' => 'administrator_release'],'keep_reserved' => ['release_reason' => 'kept_reserved'],default => []
            };
            if (str_starts_with($a, 'participant_')) {
                $p = $attendance->booking->participants()->whereHas('attendance', fn ($q) => $q->whereKey($data['participant_attendance_id']))->firstOrFail()->attendance;
                $p->update(['attendance_status' => str_replace('participant_', '', $a), 'checked_in_at' => $a === 'participant_checked_in' ? now() : null, 'checked_in_by_user_id' => $actor->id, 'method' => 'administrator']);
            } else {
                $attendance->update($updates);
                if (in_array($a, ['reverse_check_in', 'mark_no_show', 'release_court'], true)) {
                    app(BookingAccessGrantService::class)->revoke($attendance->booking, 'attendance_correction');
                }
            }
            $after = $attendance->fresh()->only(array_keys($before));
            AttendanceCorrection::create(['booking_attendance_id' => $attendance->id, 'booking_participant_attendance_id' => $data['participant_attendance_id'] ?? null, 'corrected_by_user_id' => $actor->id, 'action' => $a, 'before_snapshot' => $before, 'after_snapshot' => $after, 'reason' => $data['reason'], 'idempotency_key' => $data['idempotency_key']]);
            Audit::record('booking.attendance_corrected', $attendance, $before, $after + ['action' => $a, 'reason' => $data['reason']]);
            $this->notify('attendance.corrected', $attendance, $attendance->booking->sport_code_id, ['status' => $attendance->status]);

            return $attendance->fresh();
        });
    }

    public function suspend(User $actor, array $data): AccessEmergencyAction
    {
        abort_unless($actor->isCentralAdministrator(), 403);
        if ($e = AccessEmergencyAction::where('idempotency_key', $data['idempotency_key'])->first()) {
            return $e;
        }

        return DB::transaction(function () use ($actor, $data) {
            $q = \App\Models\BookingAccessGrant::query()->whereNotIn('status', ['revoked', 'expired', 'suspended']);
            $type = $data['scope_type'];
            $id = $data['scope_id'] ?? null;
            if ($type === 'zone') {
                $q->where('access_zone_id', $id);
            }if ($type === 'sport_code') {
                $q->whereHas('booking', fn ($x) => $x->where('sport_code_id', $id));
            }if ($type === 'venue') {
                $q->whereHas('zone', fn ($x) => $x->where('venue_id', $id));
            }if ($type === 'endpoint') {
                $q->whereHas('zone.endpoints', fn ($x) => $x->whereKey($id));
            }$grants = $q->lockForUpdate()->get();
            foreach ($grants as $g) {
                $g->update(['status' => 'suspended', 'failure_code' => 'emergency_suspension']);
                $g->commands()->where('command_type', 'provision')->whereIn('status', ['pending', 'retryable_failure'])->update(['status' => 'superseded']);
                $ep = $g->zone->endpoints()->where('enabled', true)->first();
                if ($ep) {
                    AccessCommand::firstOrCreate(['idempotency_key' => "emergency:{$data['idempotency_key']}:grant:{$g->id}:revoke"], ['command_uuid' => (string) Str::uuid(), 'booking_access_grant_id' => $g->id, 'access_endpoint_id' => $ep->id, 'command_type' => 'revoke', 'due_at' => now(), 'status' => 'pending']);
                }
            }$e = AccessEmergencyAction::create(['action_uuid' => (string) Str::uuid(), 'scope_type' => $type, 'scope_id' => $id, 'status' => 'active', 'reason' => $data['reason'], 'affected_grants' => $grants->count(), 'initiated_by_user_id' => $actor->id, 'idempotency_key' => $data['idempotency_key']]);
            AccessAlert::firstOrCreate(['source_type' => $e::class, 'source_id' => $e->id, 'alert_type' => 'emergency_suspension', 'status' => 'open'], ['severity' => 'critical', 'safe_message' => 'Temporary booking access was suspended.']);
            Audit::record('access.emergency_suspended', $e, [], ['scope_type' => $type, 'scope_id' => $id, 'affected' => $grants->count(), 'reason' => $data['reason']]);

            return $e;
        });
    }

    public function restore(User $actor, AccessEmergencyAction $action, string $reason): AccessEmergencyAction
    {
        abort_unless($actor->isCentralAdministrator(), 403);
        if ($action->status === 'restored') {
            return $action;
        }DB::transaction(function () use ($actor, $action, $reason) {
            $q = \App\Models\BookingAccessGrant::where('status', 'suspended')->where('valid_until', '>', now());
            if ($action->scope_type === 'zone') {
                $q->where('access_zone_id', $action->scope_id);
            }foreach ($q->get() as $g) {
                $g->update(['status' => 'eligible', 'failure_code' => null]);
                app(BookingAccessGrantService::class)->ensure($g->booking, app(BookingCheckInPolicyResolver::class)->resolve($g->booking, $actor));
            }$action->update(['status' => 'restored', 'restored_at' => now(), 'restored_by_user_id' => $actor->id]);
            Audit::record('access.emergency_restored', $action, [], ['reason' => $reason]);
        });

        return $action->fresh();
    }

    public function dashboard(User $user): array
    {
        $admin = $user->isCentralAdministrator();
        $codes = $admin ? null : $user->sportCodes()->pluck('sport_codes.id');
        $b = Booking::with(['facility', 'member', 'attendance', 'accessGrants.zone'])->when(! $admin, fn ($q) => $q->whereIn('sport_code_id', $codes))->latest('starts_at');

        return ['bookings' => $b->limit(100)->get(), 'corrections' => AttendanceCorrection::with('attendance.booking')->latest()->limit(100)->get(), 'emergencies' => AccessEmergencyAction::latest()->limit(50)->get(), 'notifications' => OperationalNotification::latest()->limit(100)->get(), 'report' => ['bookings' => (clone $b)->count(), 'checked_in' => (clone $b)->whereHas('attendance', fn ($q) => $q->whereIn('status', ['checked_in', 'late_checked_in']))->count(), 'no_shows' => (clone $b)->whereHas('attendance', fn ($q) => $q->where('status', 'no_show'))->count()]];
    }

    private function notify(string $event, object $subject, ?int $sportCodeId, array $payload): void
    {
        try {
            OperationalNotification::firstOrCreate(['event_type' => $event, 'subject_type' => $subject::class, 'subject_id' => $subject->id, 'user_id' => null], ['sport_code_id' => $sportCodeId, 'safe_payload' => $payload]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
