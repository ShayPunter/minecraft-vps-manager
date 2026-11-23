<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Card 5532: other sessions end on a password change or reset, and a new
 * email address needs the current password.
 */
class AccountHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_reset_signs_out_other_sessions(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->getAuthPassword();
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user->fresh())
            ->withSession(['password_hash_web' => $oldHash])
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_password_change_signs_out_other_sessions_but_not_this_one(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->getAuthPassword();

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $this->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a-new-password-123', $user->fresh()->password));

        $this->get(route('profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->withSession(['password_hash_web' => $oldHash])
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_email_change_needs_the_current_password(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])
            ->assertSessionHasErrors('current_password');

        $this->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'new@example.com',
            'current_password' => 'wrong-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame('old@example.com', $user->fresh()->email);

        $this->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'new@example.com',
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_a_name_change_needs_no_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), ['name' => 'New Name', 'email' => $user->email])
            ->assertSessionHasNoErrors();

        $this->assertSame('New Name', $user->fresh()->name);
    }
}
