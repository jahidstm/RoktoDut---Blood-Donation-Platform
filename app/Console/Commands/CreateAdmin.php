<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email} {--name=Admin}';

    protected $description = 'Create an admin account with a password typed at the prompt (use this in production, not the demo seeders)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::where('email', $email)->exists()) {
            $this->error('Give a valid email address that has no account yet.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 12 characters)');

        if (strlen($password) < 12) {
            $this->error('Password too short.');

            return self::FAILURE;
        }

        User::forceCreate([
            'name' => (string) $this->option('name'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::ADMIN->value,
            'is_onboarded' => true,
            'email_verified_at' => now(),
        ]);

        $this->info("Admin created: {$email}");

        return self::SUCCESS;
    }
}
