<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Makes the first admin (sign-up is off). Run once on a new site:
 * `php artisan app:create-admin`. An existing email is promoted to admin instead.
 */
#[Signature('app:create-admin {--name= : Display name} {--email= : Email address} {--password= : Password (prompted when left out)}')]
#[Description('Create an admin account, or make an existing account an admin')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = $this->stringOption('email') ?? text(label: 'Email address', required: true);
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $existing->role = Role::Admin;
            $existing->save();
            $this->components->info("{$existing->email} is now an admin.");

            return self::SUCCESS;
        }

        $name = $this->stringOption('name') ?? text(label: 'Name', required: true);
        $plain = $this->stringOption('password') ?? password(label: 'Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plain],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
                'password' => ['required', 'string', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $plain]);
        $user->role = Role::Admin;
        $user->email_verified_at = now();
        $user->save();

        $this->components->info("Created admin {$user->email}.");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
