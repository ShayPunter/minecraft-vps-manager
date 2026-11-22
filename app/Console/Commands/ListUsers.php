<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Lists every account with its role, so `app:create-admin` can be checked from Forge's
 * Commands panel.
 */
#[Signature('app:users')]
#[Description('List accounts with their role')]
class ListUsers extends Command
{
    public function handle(): int
    {
        $users = User::query()->orderBy('id')->get(['id', 'email', 'role']);

        if ($users->isEmpty()) {
            $this->components->info('No accounts yet. Run app:create-admin to make the first admin.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Email', 'Role'],
            $users->map(fn (User $user): array => [$user->id, $user->email, $user->role->value])->all(),
        );

        return self::SUCCESS;
    }
}
