<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        ])->assertSuccessful();

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

        $this->artisan('app:create-admin', ['--email' => 'friend@example.com'])->assertSuccessful();

        $player->refresh();
        $this->assertSame(Role::Admin, $player->role);
        $this->assertTrue(Hash::check('keep-this', $player->password));
        $this->assertSame(1, User::query()->count());
    }

    public function test_it_refuses_an_invalid_email(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'Shay',
            '--email' => 'not-an-email',
            '--password' => 'a-long-password',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
