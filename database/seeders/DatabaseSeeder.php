<?php

namespace Database\Seeders;

use App\Models\Household;
use App\Models\Member;
use App\Models\SportCode;
use App\Models\User;
use Illuminate\Database\Seeder;

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
            $code = SportCode::create($attributes);

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
            $codes[$slug]->categories()->createMany($categories);
        }

        $household = Household::create([
            'name' => 'Botha Family',
            'billing_email' => 'anna.botha@example.com',
            'phone' => '082 555 0142',
        ]);

        $anna = Member::create([
            'member_number' => 'HSC-00001',
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

        $anna->sportCodes()->attach($codes['tennis']->id, [
            'membership_category_id' => $codes['tennis']->categories()->where('name', 'Full')->value('id'),
            'status' => 'active',
            'role' => 'member',
            'joined_at' => now()->subYears(4)->toDateString(),
        ]);
        $anna->sportCodes()->attach($codes['padel']->id, [
            'membership_category_id' => $codes['padel']->categories()->where('name', 'Full')->value('id'),
            'status' => 'active',
            'role' => 'player',
            'joined_at' => now()->subYear()->toDateString(),
        ]);
        $anna->accessCredential()->create([
            'credential_type' => 'pin',
            'status' => 'active',
            'areas' => ['main_entrance', 'clubhouse', 'tennis_courts', 'padel_courts'],
            'issued_at' => now(),
        ]);

        if ($password = env('HSC_ADMIN_PASSWORD')) {
            User::create([
                'name' => 'HSC Super Administrator',
                'email' => env('HSC_ADMIN_EMAIL', 'admin@hsc.local'),
                'password' => $password,
                'role' => 'hsc_super_admin',
                'active' => true,
                'email_verified_at' => now(),
            ]);
        }
    }
}
