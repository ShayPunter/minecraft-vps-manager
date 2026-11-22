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

        // servers.spec (accounts.spec deletes Pat).
        User::factory()->create([
            'name' => 'Robin Starter',
            'email' => 'starter@example.com',
            'created_at' => now()->subYear(),
        ]);

        // server-types.spec: its own admin, so the suite stays under Fortify's
        // 5 logins a minute per email for admin@example.com (#5321).
        User::factory()->admin()->create([
            'name' => 'Casey Catalog',
            'email' => 'catalog-admin@example.com',
            'created_at' => now()->subYear(),
        ]);

        // admin-world-storage.spec (#5288), its own admin for the same reason.
        User::factory()->admin()->create([
            'name' => 'Sam Storage',
            'email' => 'storage-admin@example.com',
            'created_at' => now()->subYear(),
        ]);

        // dns.spec (#5338), its own admin for the same reason.
        User::factory()->admin()->create([
            'name' => 'Dana DNS',
            'email' => 'dns-admin@example.com',
            'created_at' => now()->subYear(),
        ]);

        // server-log.spec (#5367), its own admin for the same reason.
        User::factory()->admin()->create([
            'name' => 'Lee Logs',
            'email' => 'log-admin@example.com',
            'created_at' => now()->subYear(),
        ]);

        // software.spec (#5351), its own admin for the same reason.
        User::factory()->admin()->create([
            'name' => 'Pat Paper',
            'email' => 'software-admin@example.com',
            'created_at' => now()->subYear(),
        ]);
    }
}
