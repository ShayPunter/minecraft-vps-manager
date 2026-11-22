<?php

namespace Tests\Feature;

use App\Console\Commands\CreateAdmin;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'Shay',
            '--email' => 'admin@example.com',
            '--password' => 'a-long-password',
        ])
            ->expectsOutputToContain('Created admin@example.com (role: admin).')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertSame('Shay', $admin->name);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('a-long-password', $admin->password));
    }

    public function test_it_prompts_for_missing_values(): void
    {
        $this->artisan('app:create-admin')
            ->expectsQuestion('Email address', 'admin@example.com')
            ->expectsQuestion('Name', 'Shay')
            ->expectsQuestion('Password', 'a-long-password')
            ->assertSuccessful();

        $this->assertSame(Role::Admin, User::query()->where('email', 'admin@example.com')->firstOrFail()->role);
    }

    public function test_an_existing_account_is_promoted(): void
    {
        $player = User::factory()->create(['email' => 'friend@example.com', 'password' => 'keep-this']);

        $this->artisan('app:create-admin', ['--email' => 'friend@example.com'])
            ->expectsOutputToContain('Promoted friend@example.com (role: admin).')
            ->assertSuccessful();

        $player->refresh();
        $this->assertSame(Role::Admin, $player->role);
        $this->assertTrue(Hash::check('keep-this', $player->password));
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_mixed_case_email_promotes_the_existing_account(): void
    {
        $player = User::factory()->create(['email' => 'shay@example.com']);

        $this->artisan('app:create-admin', ['--email' => '  Shay@Example.COM ', '--no-interaction' => true])
            ->expectsOutputToContain('Promoted shay@example.com (role: admin).')
            ->assertSuccessful();

        $this->assertSame(Role::Admin, $player->refresh()->role);
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_new_account_gets_a_lowercased_email(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'Shay',
            '--email' => 'Admin@Example.com',
            '--password' => 'a-long-password',
        ])->assertSuccessful();

        $this->assertSame('admin@example.com', User::query()->sole()->email);
    }

    public function test_without_a_terminal_a_missing_email_names_the_options(): void
    {
        $this->artisan('app:create-admin', ['--no-interaction' => true])
            ->expectsOutputToContain('--name="Your Name" --email=you@example.com')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_without_a_terminal_a_new_account_needs_a_name(): void
    {
        $this->artisan('app:create-admin', ['--email' => 'new@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('No account uses new@example.com')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_without_a_terminal_a_password_is_generated_that_meets_the_production_rules(): void
    {
        $this->app['env'] = 'production';
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

        $this->artisan('app:create-admin', [
            '--name' => 'Shay',
            '--email' => 'admin@example.com',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('change it under Settings → Security')
            ->expectsOutputToContain('Created admin@example.com (role: admin).')
            ->assertSuccessful();

        $admin = User::query()->sole();
        $this->assertSame(Role::Admin, $admin->role);

        // The generated password itself is only printed, so check the generator's output
        // against the production rules directly, many times over.
        $method = new \ReflectionMethod(CreateAdmin::class, 'generatePassword');
        $command = $this->app->make(CreateAdmin::class);

        for ($i = 0; $i < 50; $i++) {
            $generated = $method->invoke($command);
            $this->assertTrue(
                Validator::make(['p' => $generated], ['p' => [Password::defaults()]])->passes(),
                "Generated password failed the production rules: {$generated}",
            );
        }
    }

    public function test_a_weak_password_in_production_creates_nothing(): void
    {
        $this->app['env'] = 'production';
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

        $this->artisan('app:create-admin', [
            '--name' => 'Shay',
            '--email' => 'admin@example.com',
            '--password' => 'a-long-password',
        ])
            ->expectsOutputToContain('Nothing was changed.')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_refuses_an_invalid_email(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'Shay',
            '--email' => 'not-an-email',
            '--password' => 'a-long-password',
        ])
            ->expectsOutputToContain('Nothing was changed.')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_app_users_lists_accounts_with_their_role(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->role = Role::Admin;
        $admin->save();
        $player = User::factory()->create(['email' => 'player@example.com']);

        $this->artisan('app:users')
            ->expectsTable(['ID', 'Email', 'Role'], [
                [$admin->id, 'admin@example.com', 'admin'],
                [$player->id, 'player@example.com', 'player'],
            ])
            ->assertSuccessful();
    }

    public function test_app_users_with_no_accounts(): void
    {
        $this->artisan('app:users')
            ->expectsOutputToContain('No accounts yet')
            ->assertSuccessful();
    }
}
