<?php

namespace Database\Seeders;

use App\Models\FinancialSetting;
use App\Models\Household;
use App\Models\Member;
use App\Models\MembershipApplication;
use App\Models\MembershipPeriod;
use App\Models\Payment;
use App\Models\SportCode;
use App\Models\SportMembership;
use App\Models\User;
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

        if ($password = env('HSC_ADMIN_PASSWORD')) {
            User::updateOrCreate(['email' => env('HSC_ADMIN_EMAIL', 'admin@hsc.local')], [
                'name' => 'HSC Super Administrator',
                'password' => $password,
                'role' => 'hsc_super_admin',
                'active' => true,
                'email_verified_at' => now(),
            ]);
        }
    }
}
