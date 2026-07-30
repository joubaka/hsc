<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateHscAdministrator extends Command
{
    protected $signature = 'hsc:admin';

    protected $description = 'Securely create or update the HSC super administrator';

    public function handle(): int
    {
        $name = $this->ask('Administrator name', 'HSC Super Administrator');
        $email = $this->ask('Email address');
        $password = $this->secret('Password (minimum 6 characters)');
        $confirmation = $this->secret('Confirm password');
        $validator = Validator::make(compact('name', 'email', 'password', 'confirmation'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6', 'same:confirmation'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role' => 'hsc_super_admin',
                'active' => true,
                'email_verified_at' => now(),
                'api_token_hash' => null,
            ]
        );

        $this->info('HSC super administrator is ready. No password was written to logs.');

        return self::SUCCESS;
    }
}
