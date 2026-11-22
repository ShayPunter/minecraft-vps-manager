<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Makes the first admin (sign-up is off). Run once on a new site:
 * `php artisan app:create-admin`. An existing email (any case) is promoted to admin instead.
 *
 * Without a terminal (Forge's Commands panel) nothing can be prompted: pass
 * `--name --email` and, optionally, `--password`. When the password is left out there, a
 * strong one is generated and printed once.
 */
#[Signature('app:create-admin {--name= : Display name} {--email= : Email address} {--password= : Password (prompted, or generated when there is no terminal)}')]
#[Description('Create an admin account, or make an existing account an admin')]
class CreateAdmin extends Command
{
    private const OPTIONS_HINT = 'Run it with --name="Your Name" --email=you@example.com and, to choose the password yourself, --password="..." (left out, a strong one is generated)';

    public function handle(): int
    {
        try {
            return $this->createOrPromote();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return $this->nothingChanged();
        }
    }

    private function createOrPromote(): int
    {
        $email = $this->stringOption('email') ?? $this->promptText('Email address');

        if ($email === null) {
            $this->components->error('No email given and there is no terminal to ask for one. '.self::OPTIONS_HINT);

            return $this->nothingChanged();
        }

        $email = Str::lower(trim($email));
        $existing = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existing !== null) {
            $existing->role = Role::Admin;
            $existing->save();

            return $this->reportSuccess($existing->id, 'Promoted');
        }

        $name = $this->stringOption('name') ?? $this->promptText('Name');

        if ($name === null) {
            $this->components->error("No account uses {$email}, so a new one is needed, but no name was given and there is no terminal to ask for one. ".self::OPTIONS_HINT);

            return $this->nothingChanged();
        }

        $plain = $this->stringOption('password');
        $generated = false;

        if ($plain === null) {
            if ($this->canPrompt()) {
                $plain = password(label: 'Password', required: true);
            } else {
                $plain = $this->generatePassword();
                $generated = true;
            }
        }

        $validator = Validator::make(
            ['name' => trim($name), 'email' => $email, 'password' => $plain],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return $this->nothingChanged();
        }

        $user = new User(['name' => trim($name), 'email' => $email, 'password' => $plain]);
        $user->role = Role::Admin;
        $user->email_verified_at = now();
        $user->save();

        if ($generated) {
            $this->components->warn('Generated password (shown once, change it under Settings → Security):');
            $this->line($plain);
        }

        return $this->reportSuccess($user->id, 'Created');
    }

    /**
     * Reads the account back from the database, so what's printed is what was saved.
     */
    private function reportSuccess(int $id, string $verb): int
    {
        $user = User::query()->findOrFail($id);
        $this->components->info("{$verb} {$user->email} (role: {$user->role->value}).");

        return self::SUCCESS;
    }

    private function nothingChanged(): int
    {
        $this->components->error('Nothing was changed.');

        return self::FAILURE;
    }

    /**
     * Prompts only work with an interactive terminal. Forge's Commands panel has none, and
     * Laravel Prompts then fails with a bare "Required.".
     */
    private function canPrompt(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        return $this->laravel->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN));
    }

    private function promptText(string $label): ?string
    {
        return $this->canPrompt() ? text(label: $label, required: true) : null;
    }

    /**
     * 24 characters with upper and lower case letters, digits and symbols, so it meets the
     * production password rules.
     */
    private function generatePassword(): string
    {
        do {
            $candidate = Str::password(24);
        } while (
            preg_match('/[a-z]/', $candidate) !== 1
            || preg_match('/[A-Z]/', $candidate) !== 1
            || preg_match('/\d/', $candidate) !== 1
            || preg_match('/[^a-zA-Z\d]/', $candidate) !== 1
        );

        return $candidate;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
