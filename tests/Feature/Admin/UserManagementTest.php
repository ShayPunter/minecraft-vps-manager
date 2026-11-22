<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function adminRoutes(): array
    {
        return [
            'index' => ['get', '/admin/users'],
            'admin root' => ['get', '/admin'],
            'create' => ['get', '/admin/users/create'],
            'store' => ['post', '/admin/users'],
            'edit' => ['get', '/admin/users/{id}/edit'],
            'update' => ['put', '/admin/users/{id}'],
            'destroy' => ['delete', '/admin/users/{id}'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_players_get_404_on_every_admin_route(string $method, string $uri): void
    {
        $player = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($player)
            ->call(strtoupper($method), str_replace('{id}', (string) $other->id, $uri), [
                'name' => 'x', 'email' => 'x@example.com', 'role' => 'admin',
            ])
            ->assertNotFound();

        $this->assertSame(Role::Player, $player->fresh()?->role);
        $this->assertNotNull($other->fresh());
    }

    #[DataProvider('adminRoutes')]
    public function test_guests_are_sent_to_the_login_page(string $method, string $uri): void
    {
        $user = User::factory()->create();

        $this->call(strtoupper($method), str_replace('{id}', (string) $user->id, $uri))
            ->assertRedirect(route('login'));
    }

    public function test_admins_see_every_user(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Alex Admin']);
        User::factory()->create(['name' => 'Pat Player', 'email' => 'pat@example.com']);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Index')
                ->has('users', 2)
                ->where('users.0.name', 'Alex Admin')
                ->where('users.0.can_delete', false)
                ->where('users.1.email', 'pat@example.com')
                ->where('users.1.role', 'player')
                ->where('users.1.can_delete', true)
                ->missing('users.1.password'));
    }

    public function test_the_create_page_lists_the_roles(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/users/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Create')
                ->where('roles', [
                    ['value' => 'admin', 'label' => 'Admin'],
                    ['value' => 'player', 'label' => 'Player'],
                ]));
    }

    public function test_admins_create_users(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Sam',
                'email' => 'sam@example.com',
                'role' => 'player',
                'password' => 'correct-horse',
                'password_confirmation' => 'correct-horse',
            ])
            ->assertRedirect('/admin/users');

        $sam = User::query()->where('email', 'sam@example.com')->firstOrFail();
        $this->assertSame('Sam', $sam->name);
        $this->assertSame(Role::Player, $sam->role);
        $this->assertNotNull($sam->email_verified_at);
        $this->assertTrue(Hash::check('correct-horse', $sam->password));
    }

    public function test_creating_validates_the_input(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => '',
                'email' => 'taken@example.com',
                'role' => 'owner',
                'password' => 'a',
                'password_confirmation' => 'b',
            ])
            ->assertSessionHasErrors(['name', 'email', 'role', 'password']);

        $this->assertSame(2, User::query()->count());
    }

    public function test_admins_edit_users_and_keep_the_password_when_left_blank(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($admin)
            ->get("/admin/users/{$player->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/Edit')
                ->where('editing.id', $player->id)
                ->where('editing.is_self', false));

        $this->actingAs($admin)
            ->put("/admin/users/{$player->id}", [
                'name' => 'Renamed',
                'email' => 'renamed@example.com',
                'role' => 'admin',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect('/admin/users');

        $player->refresh();
        $this->assertSame('Renamed', $player->name);
        $this->assertSame('renamed@example.com', $player->email);
        $this->assertSame(Role::Admin, $player->role);
        $this->assertTrue(Hash::check('old-password', $player->password));
    }

    public function test_admins_can_set_a_new_password(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();

        $this->actingAs($admin)
            ->put("/admin/users/{$player->id}", [
                'name' => $player->name,
                'email' => $player->email,
                'role' => 'player',
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])
            ->assertRedirect('/admin/users');

        $this->assertTrue(Hash::check('brand-new-pass', (string) $player->fresh()?->password));
    }

    public function test_an_email_can_be_kept_on_update_but_not_taken_from_someone_else(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create(['email' => 'mine@example.com']);
        User::factory()->create(['email' => 'theirs@example.com']);

        $this->actingAs($admin)
            ->put("/admin/users/{$player->id}", ['name' => 'P', 'email' => 'mine@example.com', 'role' => 'player'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put("/admin/users/{$player->id}", ['name' => 'P', 'email' => 'theirs@example.com', 'role' => 'player'])
            ->assertSessionHasErrors('email');
    }

    public function test_admins_cant_remove_their_own_admin_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put("/admin/users/{$admin->id}", ['name' => 'Me', 'email' => $admin->email, 'role' => 'player'])
            ->assertSessionHasErrors('role');

        $this->assertSame(Role::Admin, $admin->fresh()?->role);
    }

    public function test_admins_delete_other_users(): void
    {
        $admin = User::factory()->admin()->create();
        $player = User::factory()->create();

        $this->actingAs($admin)
            ->delete("/admin/users/{$player->id}")
            ->assertRedirect('/admin/users');

        $this->assertNull($player->fresh());
    }

    public function test_admins_cant_delete_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete("/admin/users/{$admin->id}")
            ->assertForbidden();

        $this->assertNotNull($admin->fresh());
    }

    public function test_the_role_cant_be_set_through_the_profile_page(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player)
            ->patch('/settings/profile', ['name' => 'P', 'email' => $player->email, 'role' => 'admin']);

        $this->assertSame(Role::Player, $player->fresh()?->role);
    }

    public function test_the_shared_props_say_who_is_an_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.isAdmin', true));

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.isAdmin', false)
                ->where('auth.user.role', 'player')
                ->missing('auth.user.password'));
    }

    public function test_sign_up_is_off(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'x', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertSame(0, User::query()->count());
    }
}
