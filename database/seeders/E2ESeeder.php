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

        // server-config.spec (#5377), its own admin for the same reason.
        User::factory()->admin()->create([
            'name' => 'Casey Config',
            'email' => 'config-admin@example.com',
            'created_at' => now()->subYear(),
        ]);

        // servers.spec's link-only test (#5462): a fifth admin@example.com login in a
        // minute trips Fortify's limit on a fast runner, so it has its own admin.
        User::factory()->admin()->create([
            'name' => 'Sam Share',
            'email' => 'share-admin@example.com',
            'created_at' => now()->subYear(),
        ]);

        // modpack.spec (#5352), its own admin for Fortify's login limit.
        User::factory()->admin()->create([
            'name' => 'Morgan Modpack',
            'email' => 'modpack-admin@example.com',
            'created_at' => now()->subYear(),
        ]);

        // account-hardening.spec (#5532): a player whose email the spec changes.
        User::factory()->create([
            'name' => 'Harper Hardening',
            'email' => 'hardening@example.com',
            'created_at' => now()->subYear(),
        ]);

        // two-factor.spec (#5432): the 2FA challenge's code input (vue-input-otp), typed and
        // pasted. One user and secret each: Fortify refuses a code that was used in the last
        // minute by anyone (its replay cache is keyed by the code alone).
        foreach (self::TOTP_SECRETS as $email => $secret) {
            User::factory()->create([
                'name' => 'Tess Totp',
                'email' => $email,
                'created_at' => now()->subYear(),
                'two_factor_secret' => encrypt($secret),
                'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
                'two_factor_confirmed_at' => now()->subYear(),
            ]);
        }
    }

    /** Base32; tests/e2e/two-factor.spec.ts makes the codes from them. */
    public const TOTP_SECRETS = [
        'two-factor-typed@example.com' => 'JBSWY3DPEHPK3PXP',
        'two-factor-pasted@example.com' => 'KRSXG5CTMVRXEZLU',
    ];
}
