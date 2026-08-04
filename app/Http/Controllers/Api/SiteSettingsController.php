<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingCheckInSetting;
use App\Models\BookingSetting;
use App\Models\CommunicationSetting;
use App\Models\FinancialSetting;
use App\Models\LightingSetting;
use App\Models\PublicBookingSetting;
use App\Models\SiteSetting;
use App\Services\PayFastService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteSettingsController extends Controller
{
    public function show(Request $request, PayFastService $payFast): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);

        return response()->json($this->payload($payFast));
    }

    public function update(Request $request, PayFastService $payFast): JsonResponse
    {
        abort_unless($request->user()->isCentralAdministrator(), 403);
        $data = $request->validate([
            'site.club_name' => ['required', 'string', 'max:120'], 'site.short_name' => ['required', 'string', 'max:30'],
            'site.support_email' => ['nullable', 'email', 'max:255'], 'site.support_phone' => ['nullable', 'string', 'max:40'],
            'site.website_url' => ['nullable', 'url', 'max:255'], 'site.address' => ['nullable', 'string', 'max:1000'],
            'site.logo_url' => ['nullable', 'url', 'max:255'], 'site.primary_colour' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'site.accent_colour' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'site.terms_url' => ['nullable', 'url', 'max:255'],
            'site.privacy_url' => ['nullable', 'url', 'max:255'], 'site.facility_rules_url' => ['nullable', 'url', 'max:255'],
            'booking.timezone' => ['required', 'timezone:all'], 'booking.currency' => ['required', 'string', 'size:3'],
            'booking.hold_minutes' => ['required', 'integer', 'between:1,60'], 'booking.default_advance_days' => ['required', 'integer', 'between:0,365'],
            'booking.default_minimum_notice_minutes' => ['required', 'integer', 'between:0,10080'], 'booking.default_min_duration_minutes' => ['required', 'integer', 'between:15,480'],
            'booking.default_max_duration_minutes' => ['required', 'integer', 'between:15,1440'], 'booking.default_start_interval_minutes' => ['required', 'integer', 'between:5,120'],
            'booking.default_max_future_bookings' => ['required', 'integer', 'between:0,100'], 'booking.default_hourly_rate_cents' => ['required', 'integer', 'between:0,10000000'],
            'booking.financial_standing_enabled' => ['required', 'boolean'],
            'public.enabled' => ['required', 'boolean'], 'public.dry_run_checkout_enabled' => ['required', 'boolean'],
            'public.email_verification_required' => ['required', 'boolean'], 'public.mobile_verification_required' => ['required', 'boolean'],
            'public.payment_required' => ['required', 'boolean'], 'public.temporary_access_eligible' => ['required', 'boolean'],
            'public.hold_minutes' => ['required', 'integer', 'between:1,60'], 'public.maximum_active_holds' => ['required', 'integer', 'between:1,20'],
            'public.maximum_future_bookings' => ['required', 'integer', 'between:1,100'], 'public.maximum_participants' => ['required', 'integer', 'between:1,50'],
            'public.terms_version' => ['required', 'string', 'max:30'], 'public.privacy_version' => ['required', 'string', 'max:30'], 'public.facility_rules_version' => ['required', 'string', 'max:30'],
            'lighting.automation_enabled' => ['required', 'boolean'], 'lighting.dry_run' => ['required', 'boolean'], 'lighting.pre_start_minutes' => ['required', 'integer', 'between:0,120'],
            'lighting.post_end_minutes' => ['required', 'integer', 'between:0,120'], 'lighting.minimum_billable_minutes' => ['required', 'integer', 'between:1,240'],
            'lighting.billing_increment_minutes' => ['required', 'integer', 'between:1,120'], 'lighting.require_paid' => ['required', 'boolean'], 'lighting.members_may_select' => ['required', 'boolean'],
            'check_in.enabled' => ['required', 'boolean'], 'check_in.opens_minutes_before' => ['required', 'integer', 'between:0,240'], 'check_in.closes_minutes_after' => ['required', 'integer', 'between:0,240'],
            'check_in.allow_member_self_check_in' => ['required', 'boolean'], 'check_in.allow_qr' => ['required', 'boolean'], 'check_in.allow_short_code' => ['required', 'boolean'],
            'check_in.no_show_grace_minutes' => ['required', 'integer', 'between:0,240'], 'check_in.automatic_no_show_enabled' => ['required', 'boolean'], 'check_in.automatic_release_enabled' => ['required', 'boolean'],
            'check_in.automatic_release_delay_minutes' => ['required', 'integer', 'between:0,240'], 'check_in.allow_without_payment' => ['required', 'boolean'],
            'finance.default_currency' => ['required', 'string', 'size:3'], 'finance.invoice_prefix' => ['required', 'string', 'max:20'], 'finance.receipt_prefix' => ['required', 'string', 'max:20'],
            'finance.credit_note_prefix' => ['required', 'string', 'max:20'], 'finance.financial_year_start_month' => ['required', 'integer', 'between:1,12'], 'finance.default_invoice_due_days' => ['required', 'integer', 'between:0,365'],
            'finance.banking_reference_instructions' => ['nullable', 'string', 'max:2000'], 'finance.payment_reference_format' => ['required', 'string', 'max:255'],
            'finance.joining_fee_owner_type' => ['required', 'in:sport_code,central_hsc'], 'finance.generate_zero_value_invoices' => ['required', 'boolean'],
            'communications.enabled' => ['required', 'boolean'], 'communications.announcements_enabled' => ['required', 'boolean'],
            'communications.booking_confirmations_enabled' => ['required', 'boolean'], 'communications.booking_cancellations_enabled' => ['required', 'boolean'],
            'communications.booking_reminders_enabled' => ['required', 'boolean'], 'communications.booking_reminder_hours' => ['required', 'integer', 'between:1,168'],
            'communications.invoice_emails_enabled' => ['required', 'boolean'], 'communications.payment_emails_enabled' => ['required', 'boolean'],
            'communications.respect_consent_for_transactional' => ['required', 'boolean'], 'communications.sender_name' => ['required', 'string', 'max:120'],
            'communications.sender_email' => ['nullable', 'email', 'max:255'], 'communications.reply_to_email' => ['nullable', 'email', 'max:255'],
            'communications.email_footer' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($data) {
            $models = [
                [SiteSetting::firstOrCreate([]), $data['site']], [BookingSetting::firstOrCreate([]), $data['booking']],
                [PublicBookingSetting::firstOrCreate(['sport_code_id' => null]), $data['public']], [LightingSetting::firstOrCreate(['sport_code_id' => null]), $data['lighting']],
                [BookingCheckInSetting::firstOrCreate(['sport_code_id' => null]), $data['check_in']], [FinancialSetting::firstOrCreate([]), $data['finance']],
                [CommunicationSetting::current(), $data['communications']],
            ];
            foreach ($models as [$model, $values]) { $old = $model->getAttributes(); $model->update($values); Audit::record('site_settings.updated', $model, $old, $model->fresh()->getAttributes()); }
        });

        return response()->json($this->payload($payFast));
    }

    private function payload(PayFastService $payFast): array
    {
        return [
            'site' => SiteSetting::firstOrCreate([])->fresh(), 'booking' => BookingSetting::firstOrCreate([])->fresh(),
            'public' => PublicBookingSetting::firstOrCreate(['sport_code_id' => null])->fresh(), 'lighting' => LightingSetting::firstOrCreate(['sport_code_id' => null])->fresh(),
            'check_in' => BookingCheckInSetting::firstOrCreate(['sport_code_id' => null])->fresh(), 'finance' => FinancialSetting::firstOrCreate([])->fresh(),
            'communications' => CommunicationSetting::current()->fresh(),
            'payment' => ['payfast_configured' => $payFast->configured(), 'payfast_sandbox' => (bool) config('services.payfast.sandbox')],
        ];
    }
}
