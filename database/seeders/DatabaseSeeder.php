<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingAllowancePolicy;
use App\Models\BookingHold;
use App\Models\BookingPolicy;
use App\Models\BookingPricingRule;
use App\Models\BookingSeries;
use App\Models\BookingSetting;
use App\Models\Facility;
use App\Models\FacilityGroup;
use App\Models\FacilityTag;
use App\Models\FinancialSetting;
use App\Models\Household;
use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\MembershipPeriod;
use App\Models\Payment;
use App\Models\SportCode;
use App\Models\SportMembership;
use App\Models\User;
use App\Models\Venue;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $codes = collect([
            ['name' => 'Tennis', 'slug' => 'tennis', 'colour' => '#2E7D32'],
            ['name' => 'Padel', 'slug' => 'padel', 'colour' => '#EF6C00'],
            ['name' => 'Squash', 'slug' => 'squash', 'colour' => '#6A1B9A'],
            ['name' => 'Netball', 'slug' => 'netball', 'colour' => '#C2185B'],
        ])->mapWithKeys(function (array $attributes) {
            $code = SportCode::updateOrCreate(['slug' => $attributes['slug']], $attributes);

            return [$attributes['slug'] => $code];
        });

        $categoryData = [
            'tennis' => [
                ['name' => 'Full', 'code_fee_cents' => 240000, 'hsc_levy_cents' => 65000],
                ['name' => 'Social', 'code_fee_cents' => 125000, 'hsc_levy_cents' => 65000],
                ['name' => 'Country', 'code_fee_cents' => 145000, 'hsc_levy_cents' => 65000],
                ['name' => 'Junior', 'code_fee_cents' => 70000, 'hsc_levy_cents' => 25000, 'maximum_age' => 17],
            ],
            'padel' => [
                ['name' => 'Full', 'code_fee_cents' => 180000, 'hsc_levy_cents' => 65000],
                ['name' => 'Junior', 'code_fee_cents' => 80000, 'hsc_levy_cents' => 25000, 'maximum_age' => 17],
            ],
            'squash' => [
                ['name' => 'Full', 'code_fee_cents' => 165000, 'hsc_levy_cents' => 65000],
                ['name' => 'Social', 'code_fee_cents' => 90000, 'hsc_levy_cents' => 65000],
                ['name' => 'Junior', 'code_fee_cents' => 60000, 'hsc_levy_cents' => 25000, 'maximum_age' => 17],
            ],
            'netball' => [
                ['name' => 'Senior Player', 'code_fee_cents' => 130000, 'hsc_levy_cents' => 65000],
                ['name' => 'Junior Player', 'code_fee_cents' => 55000, 'hsc_levy_cents' => 25000, 'maximum_age' => 17],
            ],
        ];

        foreach ($categoryData as $slug => $categories) {
            foreach ($categories as $category) {
                $codes[$slug]->categories()->updateOrCreate(['name' => $category['name']], $category);
            }
        }

        $household = Household::updateOrCreate(['name' => 'Botha Family'], [
            'billing_email' => 'anna.botha@example.com',
            'phone' => '082 555 0142',
        ]);

        $anna = Member::updateOrCreate(['member_number' => 'HSC-00001'], [
            'household_id' => $household->id,
            'first_name' => 'Anna',
            'last_name' => 'Botha',
            'date_of_birth' => '1987-04-18',
            'email' => 'anna.botha@example.com',
            'mobile' => '082 555 0142',
            'status' => 'active',
            'household_role' => 'Primary contact',
            'emergency_contact_name' => 'Pieter Botha',
            'emergency_contact_mobile' => '083 555 0188',
            'email_consent' => true,
            'whatsapp_consent' => true,
        ]);

        $anna->sportCodes()->syncWithoutDetaching([$codes['tennis']->id => [
            'membership_category_id' => $codes['tennis']->categories()->where('name', 'Full')->value('id'),
            'status' => 'active',
            'role' => 'member',
            'joined_at' => now()->subYears(4)->toDateString(),
        ]]);
        $anna->sportCodes()->syncWithoutDetaching([$codes['padel']->id => [
            'membership_category_id' => $codes['padel']->categories()->where('name', 'Full')->value('id'),
            'status' => 'active',
            'role' => 'player',
            'joined_at' => now()->subYear()->toDateString(),
        ]]);
        $anna->accessCredential()->updateOrCreate(['credential_type' => 'pin'], [
            'credential_type' => 'pin',
            'status' => 'active',
            'areas' => ['main_entrance', 'clubhouse', 'tennis_courts', 'padel_courts'],
            'issued_at' => now(),
        ]);

        $period = MembershipPeriod::updateOrCreate(['name' => now()->year.' Membership Year'], [
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
            'applications_open_on' => now()->subMonth()->toDateString(),
            'applications_close_on' => now()->addMonths(2)->toDateString(),
            'renewal_deadline' => now()->addMonth()->toDateString(),
            'status' => 'open',
        ]);
        MembershipApplication::updateOrCreate([
            'member_id' => $anna->id,
            'sport_code_id' => $codes['squash']->id,
            'membership_period_id' => $period->id,
            'application_type' => 'additional_sport',
        ], [
            'membership_category_id' => $codes['squash']->categories()->where('name', 'Full')->value('id'),
            'status' => 'draft',
        ]);
        MembershipApplication::updateOrCreate([
            'member_id' => $anna->id,
            'sport_code_id' => $codes['tennis']->id,
            'membership_period_id' => $period->id,
            'application_type' => 'renewal',
        ], [
            'membership_category_id' => $codes['tennis']->categories()->where('name', 'Full')->value('id'),
            'status' => 'draft',
        ]);

        FinancialSetting::firstOrCreate([], [
            'default_currency' => 'ZAR',
            'invoice_prefix' => 'INV',
            'receipt_prefix' => 'REC',
            'credit_note_prefix' => 'CN',
            'financial_year_start_month' => 1,
            'default_invoice_due_days' => 30,
            'payment_reference_format' => '{member_number}',
            'joining_fee_owner_type' => 'sport_code',
            'generate_zero_value_invoices' => false,
        ]);

        $systemUser = User::updateOrCreate(['email' => 'finance-seeder@hsc.local'], [
            'name' => 'Finance demonstration system user',
            'password' => Hash::make(str()->random(48)),
            'role' => 'hsc_super_admin',
            'active' => false,
            'email_verified_at' => now(),
        ]);
        $invoiceService = app(InvoiceService::class);
        $paymentService = app(PaymentService::class);
        $demoInvoices = collect();
        foreach (['tennis', 'squash'] as $slug) {
            $code = $codes[$slug];
            $category = $code->categories()->where('name', 'Full')->first();
            $application = MembershipApplication::where([
                'member_id' => $anna->id,
                'sport_code_id' => $code->id,
                'membership_period_id' => $period->id,
                'application_type' => $slug === 'tennis' ? 'renewal' : 'additional_sport',
            ])->firstOrFail();
            $application->update([
                'status' => 'approved',
                'sport_code_fee_cents' => $category->code_fee_cents,
                'hsc_levy_cents' => $category->hsc_levy_cents,
                'joining_fee_cents' => 0,
                'discount_cents' => 0,
                'total_payable_cents' => $category->code_fee_cents + $category->hsc_levy_cents,
                'billing_frequency' => 'annual',
                'currency' => 'ZAR',
                'submitted_at' => now(),
                'decided_at' => now(),
                'decided_by_user_id' => $systemUser->id,
            ]);
            $membership = SportMembership::updateOrCreate(['source_application_id' => $application->id], [
                'member_id' => $anna->id,
                'sport_code_id' => $code->id,
                'membership_category_id' => $category->id,
                'membership_period_id' => $period->id,
                'status' => 'active',
                'role' => 'member',
                'joined_or_renewed_on' => $period->starts_on,
                'starts_on' => $period->starts_on,
                'ends_on' => $period->ends_on,
                'sport_code_fee_cents' => $application->sport_code_fee_cents,
                'hsc_levy_cents' => $application->hsc_levy_cents,
                'total_cents' => $application->total_payable_cents,
                'billing_frequency' => 'annual',
            ]);
            $result = $invoiceService->generateMembershipInvoice($systemUser, $membership);
            $invoice = $result['invoice'];
            if ($invoice->status === 'draft') {
                $invoice = $invoiceService->issue($systemUser, $invoice);
            }
            $demoInvoices->put($slug, $invoice);
        }

        if (! Payment::where('external_reference', 'SEED-PARTIAL-001')->exists()) {
            $payment = $paymentService->record($systemUser, [
                'payment_reference' => 'SEED-PARTIAL',
                'member_id' => $anna->id,
                'payer_name' => 'Anna Botha',
                'currency' => 'ZAR',
                'amount' => 100000,
                'received_on' => now()->toDateString(),
                'payment_method' => 'eft',
                'external_reference' => 'SEED-PARTIAL-001',
                'bank_reference' => 'HSC-00001',
                'import_fingerprint' => null,
                'source' => 'seed',
            ]);
            $payment = $paymentService->confirm($systemUser, $payment);
            $paymentService->allocate($systemUser, $payment, $demoInvoices['tennis'], 100000, 'seed-partial-allocation');
        }
        if (! Payment::where('external_reference', 'SEED-CREDIT-001')->exists()) {
            $paymentService->confirm($systemUser, $paymentService->record($systemUser, [
                'payment_reference' => 'SEED-UNALLOCATED',
                'member_id' => $anna->id,
                'payer_name' => 'Anna Botha',
                'currency' => 'ZAR',
                'amount' => 50000,
                'received_on' => now()->toDateString(),
                'payment_method' => 'eft',
                'external_reference' => 'SEED-CREDIT-001',
                'bank_reference' => 'HSC-00001 CREDIT',
                'import_fingerprint' => null,
                'source' => 'seed',
            ]));
        }
        if (! Payment::where('external_reference', 'SEED-PAID-001')->exists()) {
            $invoice = $demoInvoices['squash'];
            $payment = $paymentService->confirm($systemUser, $paymentService->record($systemUser, [
                'payment_reference' => 'SEED-PAID',
                'member_id' => $anna->id,
                'payer_name' => 'Anna Botha',
                'currency' => 'ZAR',
                'amount' => $invoice->outstanding_amount,
                'received_on' => now()->toDateString(),
                'payment_method' => 'card',
                'external_reference' => 'SEED-PAID-001',
                'bank_reference' => null,
                'import_fingerprint' => null,
                'source' => 'seed',
            ]));
            $paymentService->allocate($systemUser, $payment, $invoice, $invoice->outstanding_amount, 'seed-paid-allocation');
        }

        BookingSetting::firstOrCreate([], ['timezone' => 'Africa/Johannesburg', 'currency' => 'ZAR', 'hold_minutes' => 10, 'default_hourly_rate_cents' => 12000]);
        $venue = Venue::updateOrCreate(['code' => 'HSC-MAIN'], ['name' => 'Hermanus Sports Club', 'address' => 'Hermanus', 'timezone' => 'Africa/Johannesburg', 'active' => true]);
        $groups = collect([
            ['code' => 'TENNIS-COURTS', 'name' => 'Tennis courts', 'sport_code_id' => $codes['tennis']->id],
            ['code' => 'PADEL-COURTS', 'name' => 'Padel courts', 'sport_code_id' => $codes['padel']->id],
            ['code' => 'OTHER-COURTS', 'name' => 'Other courts', 'sport_code_id' => null],
        ])->mapWithKeys(fn ($row) => [$row['code'] => FacilityGroup::updateOrCreate(['code' => $row['code']], $row)]);
        $tags = collect(['Floodlit', 'Hard court', 'Outdoor', 'Indoor', 'Wheelchair accessible'])->mapWithKeys(function ($name) {
            $tag = FacilityTag::updateOrCreate(['slug' => str($name)->slug()], ['name' => $name]);

            return [$name => $tag];
        });
        $facilityRows = [];
        foreach (range(1, 4) as $number) {
            $facilityRows[] = ["Tennis Court {$number}", "TEN-{$number}", 'tennis_court', 'tennis', 'TENNIS-COURTS'];
        }
        foreach (range(1, 2) as $number) {
            $facilityRows[] = ["Padel Court {$number}", "PAD-{$number}", 'padel_court', 'padel', 'PADEL-COURTS'];
        }
        $facilityRows[] = ['Squash Court 1', 'SQU-1', 'squash_court', 'squash', 'OTHER-COURTS'];
        $facilityRows[] = ['Netball Court', 'NET-1', 'netball_court', 'netball', 'OTHER-COURTS'];
        $facilities = collect();
        foreach ($facilityRows as $index => [$name, $code, $type, $sport, $group]) {
            $facility = Facility::updateOrCreate(['code' => $code], [
                'venue_id' => $venue->id, 'sport_code_id' => $codes[$sport]->id, 'facility_group_id' => $groups[$group]->id,
                'name' => $name, 'facility_type' => $type, 'surface_type' => $type === 'squash_court' ? 'timber' : 'hard',
                'indoor' => $type === 'squash_court', 'capacity' => 4, 'description' => "Bookable {$name} at Hermanus Sports Club.",
                'lighting_available' => in_array($sport, ['tennis', 'padel']), 'publicly_visible' => true, 'bookable' => true,
                'active' => true, 'display_order' => $index + 1, 'default_duration_minutes' => 60, 'start_interval_minutes' => 30,
            ]);
            $facility->tags()->syncWithoutDetaching([$tags['Hard court']->id, $tags[$type === 'squash_court' ? 'Indoor' : 'Outdoor']->id]);
            foreach (range(0, 6) as $day) {
                $facility->openingHours()->updateOrCreate(
                    ['day_of_week' => $day, 'opens_at' => '07:00:00', 'closes_at' => '21:00:00'],
                    ['active' => true]
                );
            }
            $facilities->put($code, $facility);
        }
        BookingPolicy::updateOrCreate(['name' => 'Central adult booking defaults'], [
            'policy_type' => 'duration', 'scope_type' => 'central', 'scope_id' => null, 'profile' => 'adult_member',
            'rules' => ['advance_days' => 7, 'min_duration_minutes' => 30, 'max_duration_minutes' => 120, 'max_future_bookings' => 4, 'max_daily_bookings' => 2],
            'priority' => 1, 'version' => 1, 'active' => true,
        ]);
        BookingPolicy::updateOrCreate(['name' => 'Junior booking limits'], [
            'policy_type' => 'duration', 'scope_type' => 'central', 'scope_id' => null, 'profile' => 'junior_member',
            'rules' => ['advance_days' => 5, 'max_duration_minutes' => 90, 'max_future_bookings' => 3], 'priority' => 2, 'version' => 1, 'active' => true,
        ]);
        BookingPolicy::updateOrCreate(['name' => 'Standard cancellation'], [
            'policy_type' => 'cancellation', 'scope_type' => 'central', 'rules' => ['cancellation_hours' => 24, 'refund_percentage' => 100],
            'priority' => 1, 'version' => 1, 'active' => true,
        ]);
        BookingPricingRule::updateOrCreate(['name' => 'Off-peak court rate'], [
            'scope_type' => 'central', 'pricing_method' => 'hourly', 'amount_cents' => 12000, 'guest_fee_cents' => 5000,
            'priority' => 1, 'peak' => false, 'active' => true,
        ]);
        BookingPricingRule::updateOrCreate(['name' => 'Tennis evening peak'], [
            'scope_type' => 'facility_group', 'scope_id' => $groups['TENNIS-COURTS']->id, 'starts_at' => '17:00', 'ends_at' => '20:00',
            'pricing_method' => 'hourly', 'amount_cents' => 18000, 'guest_fee_cents' => 5000, 'priority' => 20, 'peak' => true, 'active' => true,
        ]);
        BookingAllowancePolicy::updateOrCreate(['name' => 'Adult weekly included hour'], [
            'sport_code_id' => $codes['tennis']->id, 'facility_group_id' => $groups['TENNIS-COURTS']->id, 'profile' => 'adult_member',
            'included_minutes' => 60, 'included_bookings' => 1, 'recurrence' => 'week', 'peak_scope' => 'all', 'active' => true,
        ]);
        $facilities['TEN-4']->closures()->updateOrCreate(['title' => 'Court resurfacing'], [
            'closure_type' => 'maintenance', 'starts_at' => now()->addDays(3)->setTime(9, 0), 'ends_at' => now()->addDays(3)->setTime(13, 0),
            'public_message' => 'Temporarily unavailable for maintenance.', 'private_notes' => 'Seed demonstration closure.',
            'active' => true, 'created_by_user_id' => $systemUser->id,
        ]);
        BookingHold::updateOrCreate(['idempotency_key' => '00000000-0000-4000-8000-000000000001'], [
            'hold_reference' => '10000000-0000-4000-8000-000000000001', 'facility_id' => $facilities['PAD-1']->id,
            'user_id' => $systemUser->id, 'member_id' => $anna->id, 'starts_at' => now()->addDays(2)->setTime(10, 0),
            'ends_at' => now()->addDays(2)->setTime(11, 0), 'expires_at' => now()->addMinutes(10), 'status' => 'active',
            'rule_snapshot' => ['seed' => true], 'price_snapshot' => ['total_amount' => 12000], 'cancellation_snapshot' => ['version' => 1],
        ]);
        Booking::updateOrCreate(['confirmation_key' => '00000000-0000-4000-8000-000000000002'], [
            'booking_reference' => 'BKG-SEED-CONFIRMED', 'facility_id' => $facilities['TEN-1']->id, 'sport_code_id' => $codes['tennis']->id,
            'member_id' => $anna->id, 'created_by_user_id' => $systemUser->id, 'status' => 'confirmed', 'payment_status' => 'covered_by_allowance',
            'starts_at' => now()->addDays(2)->setTime(14, 0), 'ends_at' => now()->addDays(2)->setTime(15, 0), 'duration_minutes' => 60,
            'profile' => 'adult_member', 'currency' => 'ZAR', 'gross_amount' => 12000, 'allowance_amount' => 12000, 'total_amount' => 0,
            'rule_snapshot' => ['seed' => true], 'price_snapshot' => ['gross_amount' => 12000, 'allowance_amount' => 12000, 'total_amount' => 0],
            'allowance_snapshot' => ['minutes' => 60], 'cancellation_snapshot' => ['version' => 1, 'refund_percentage' => 100],
        ]);
        Booking::updateOrCreate(['confirmation_key' => '00000000-0000-4000-8000-000000000003'], [
            'booking_reference' => 'BKG-SEED-CANCELLED', 'facility_id' => $facilities['TEN-2']->id, 'sport_code_id' => $codes['tennis']->id,
            'member_id' => $anna->id, 'created_by_user_id' => $systemUser->id, 'status' => 'cancelled', 'payment_status' => 'not_required',
            'starts_at' => now()->addDay()->setTime(12, 0), 'ends_at' => now()->addDay()->setTime(13, 0), 'duration_minutes' => 60,
            'profile' => 'adult_member', 'currency' => 'ZAR', 'total_amount' => 0, 'rule_snapshot' => ['seed' => true],
            'price_snapshot' => ['total_amount' => 0], 'cancellation_snapshot' => ['version' => 1], 'cancelled_at' => now(),
            'cancellation_reason' => 'Seed demonstration',
        ]);
        BookingSeries::updateOrCreate(['idempotency_key' => '00000000-0000-4000-8000-000000000101'], [
            'series_reference' => 'SER-DEMO-WEEKLY', 'facility_id' => $facilities['TEN-1']->id,
            'sport_code_id' => $codes['tennis']->id, 'member_id' => $anna->id, 'created_by_user_id' => $systemUser->id,
            'series_type' => 'member_recurring', 'status' => 'draft', 'approval_status' => 'draft', 'version' => 1,
            'starts_on' => now()->addDays(5)->toDateString(), 'start_time' => '16:00:00', 'duration_minutes' => 60,
            'timezone' => 'Africa/Johannesburg', 'recurrence_frequency' => 'daily', 'recurrence_interval' => 1,
            'days_of_week' => null, 'occurrence_limit' => 2, 'guest_count' => 0,
            'participant_template' => [], 'billing_strategy' => 'one_invoice_for_series', 'booking_type' => 'court',
            'member_notes' => 'Seed demonstration recurring request.',
        ]);
        BookingSeries::updateOrCreate(['idempotency_key' => '00000000-0000-4000-8000-000000000102'], [
            'series_reference' => 'SER-DEMO-BLOCK', 'facility_id' => $facilities['TEN-2']->id,
            'sport_code_id' => $codes['tennis']->id, 'member_id' => $anna->id, 'created_by_user_id' => $systemUser->id,
            'series_type' => 'coach_block', 'status' => 'submitted', 'approval_status' => 'submitted', 'version' => 1,
            'starts_on' => now()->addDays(6)->toDateString(), 'start_time' => '09:00:00', 'duration_minutes' => 90,
            'timezone' => 'Africa/Johannesburg', 'recurrence_frequency' => 'weekly', 'recurrence_interval' => 1,
            'days_of_week' => [(int) now()->addDays(6)->isoWeekday()], 'occurrence_limit' => 6, 'guest_count' => 0,
            'participant_template' => [], 'billing_strategy' => 'invoice_each_occurrence', 'booking_type' => 'court',
            'submitted_at' => now(), 'member_notes' => 'Seed demonstration block-booking request.',
        ]);

        $adminEmail = env('HSC_ADMIN_EMAIL', 'admin@hsc.local');
        $adminPasswordHash = env('HSC_ADMIN_PASSWORD_HASH');

        if (! $adminPasswordHash || ! Hash::info($adminPasswordHash)['algo']) {
            throw new \RuntimeException('HSC_ADMIN_PASSWORD_HASH must contain a valid password hash before seeding the protected super-administrator account.');
        }

        $admin = User::firstOrNew(['email' => $adminEmail]);
        $admin->forceFill([
            'name' => 'HSC Super Administrator',
            'role' => 'hsc_super_admin',
            'active' => true,
            'protected_account' => true,
            'email_verified_at' => now(),
        ]);
        $admin->setRawAttributes([...$admin->getAttributes(), 'password' => $adminPasswordHash]);
        $admin->save();
    }
}
