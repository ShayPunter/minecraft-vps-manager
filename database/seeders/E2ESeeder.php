<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Accounts for the Playwright suite (tests/e2e). Every password is "password".
 * Add new records at the end: earlier specs rely on these ids.
 */
class E2ESeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Alex Admin',
            'email' => 'admin@example.com',
            'created_at' => now()->subYear(),
        ]);

        User::factory()->create([
            'name' => 'Pat Player',
            'email' => 'player@example.com',
            'created_at' => now()->subYear(),
        ]);
    }
}
