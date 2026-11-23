<?php

namespace Tests\Feature\Servers;

use App\Enums\RunStatus;
use App\Enums\Visibility;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * Link-only servers (#5462): only their share link shows and starts them.
 */
class SharedServerTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();
    }

    private function linkOnly(): Server
    {
        return Server::factory()->linkOnly()->create(['name' => 'Community SMP', 'slug' => 'community-smp']);
    }

    private function path(Server $server): string
    {
        $token = (string) $server->share_token;

        return '/s/'.$token;
    }

    public function test_a_link_only_server_gets_a_token_that_is_stored_encrypted_and_hashed(): void
    {
        $server = $this->linkOnly();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', (string) $server->share_token);
        $this->assertSame(hash('sha256', (string) $server->share_token), $server->share_token_hash);
        $this->assertSame(url($this->path($server)), $server->shareUrl());

        $raw = DB::table('servers')->where('id', $server->id)->value('share_token');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString((string) $server->share_token, $raw);

        // Not in the model's array form (e.g. a JSON answer).
        $this->assertArrayNotHasKey('share_token', $server->toArray());
        $this->assertArrayNotHasKey('share_token_hash', $server->toArray());
    }

    public function test_other_servers_have_no_token_and_switching_away_kills_the_link(): void
    {
        $private = Server::factory()->create();
        $public = Server::factory()->public()->create();
        $this->assertNull($private->share_token_hash);
        $this->assertNull($public->share_token_hash);
        $this->assertNull($private->shareUrl());

        $server = $this->linkOnly();
        $old = $this->path($server);

        $server->update(['visibility' => Visibility::Private]);
        $this->assertNull($server->share_token);
        $this->assertNull($server->share_token_hash);
        $this->get($old)->assertNotFound();

        // Back to link only: a new link, the old one stays dead.
        $server->update(['visibility' => Visibility::Link]);
        $this->assertNotSame($old, $this->path($server));
        $this->get($old)->assertNotFound();
        $this->get($this->path($server))->assertOk();
    }

    public function test_a_guest_with_the_link_sees_only_that_server_and_can_start_it(): void
    {
        $server = $this->linkOnly();
        Server::factory()->public()->create(['name' => 'Open', 'slug' => 'open']);

        $this->get($this->path($server))
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Shared')
                ->where('server.slug', 'community-smp')
                ->where('server.visibility', 'link')
                ->where('server.can_start', true)
                ->where('server.can_stop', false)
                ->where('token', $server->share_token)
                ->missing('servers'));

        $this->from($this->path($server))
            ->post($this->path($server).'/start')
            ->assertRedirect($this->path($server))
            ->assertInertiaFlash('toast.type', 'success');

        $run = ServerRun::query()->sole();
        $this->assertSame($server->id, $run->active_server_id);
        $this->assertNull($run->started_by);

        // Started: no second Start, and a guest still can't stop it.
        $this->get($this->path($server))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('server.can_start', false)
                ->where('server.can_stop', false)
                ->where('server.status', RunStatus::Booting->value));
        $this->post('/servers/community-smp/stop')->assertRedirect('/login');
    }

    public function test_a_wrong_or_malformed_token_is_a_404(): void
    {
        $server = $this->linkOnly();

        $this->get('/s/'.str_repeat('a', 40))->assertNotFound();
        $this->post('/s/'.str_repeat('a', 40).'/start')->assertNotFound();
        $this->get('/s/short')->assertNotFound();
        $this->get('/s/'.$server->slug)->assertNotFound();
        $this->assertSame(0, ServerRun::query()->count());
    }

    public function test_a_private_or_public_server_never_opens_by_token(): void
    {
        $server = $this->linkOnly();
        $token = (string) $server->share_token;

        // A stale hash left on a non-link server (shouldn't happen) still doesn't open it.
        DB::table('servers')->where('id', $server->id)->update(['visibility' => 'private']);
        $this->get('/s/'.$token)->assertNotFound();
        DB::table('servers')->where('id', $server->id)->update(['visibility' => 'public']);
        $this->get('/s/'.$token)->assertNotFound();
    }

    public function test_it_is_hidden_from_the_home_page_the_dashboard_and_the_slug_start_route(): void
    {
        $this->linkOnly();
        Server::factory()->create(['name' => 'Friends', 'slug' => 'friends']);
        $player = User::factory()->create();

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->has('servers', 0));
        $this->post('/servers/community-smp/start')->assertNotFound();

        $this->actingAs($player)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('servers', 1)
                ->where('servers.0.slug', 'friends'));
        $this->actingAs($player)->post('/servers/community-smp/start')->assertNotFound();
        $this->assertSame(0, ServerRun::query()->count());
    }

    public function test_a_signed_in_player_with_the_link_can_start_it_and_stop_their_own_run(): void
    {
        $server = $this->linkOnly();
        $player = User::factory()->create();

        $this->actingAs($player)->from($this->path($server))
            ->post($this->path($server).'/start')
            ->assertInertiaFlash('toast.type', 'success');
        $run = ServerRun::query()->sole();
        $this->assertSame($player->id, $run->started_by);

        $this->actingAs($player)->get($this->path($server))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('server.can_stop', true));
    }

    public function test_admins_see_it_everywhere_and_can_replace_the_link(): void
    {
        $server = $this->linkOnly();
        $old = $this->path($server);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('servers.0.slug', 'community-smp'));
        $this->actingAs($admin)->get('/admin/servers/community-smp/edit')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('server.visibility', 'link')
                ->where('server.share_url', url($old))
                ->missing('server.share_token')
                ->missing('server.share_token_hash'));

        $this->actingAs($admin)->from('/admin/servers/community-smp/edit')
            ->post('/admin/servers/community-smp/share-link')
            ->assertRedirect('/admin/servers/community-smp/edit')
            ->assertInertiaFlash('toast.type', 'success');

        $server->refresh();
        $this->assertNotSame($old, $this->path($server));
        $this->get($old)->assertNotFound();
        $this->get($this->path($server))->assertOk();

        // An admin can also start it from Admin / the dashboard by slug.
        $this->actingAs($admin)->post('/servers/community-smp/start')->assertRedirect();
        $this->assertSame(1, ServerRun::query()->count());
    }

    public function test_only_admins_can_replace_a_link_and_only_for_link_only_servers(): void
    {
        $server = $this->linkOnly();
        $token = $server->share_token;
        $player = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $private = Server::factory()->create(['slug' => 'friends']);

        $this->post('/admin/servers/community-smp/share-link')->assertRedirect('/login');
        $this->actingAs($player)->post('/admin/servers/community-smp/share-link')->assertNotFound();
        $this->assertSame($token, $server->refresh()->share_token);

        $this->actingAs($admin)->post('/admin/servers/friends/share-link')
            ->assertInertiaFlash('toast.type', 'error');
        $this->assertNull($private->refresh()->share_token_hash);
    }

    public function test_the_admin_form_saves_each_visibility(): void
    {
        $admin = User::factory()->admin()->create();
        $server = Server::factory()->create(['slug' => 'friends', 'name' => 'Friends']);
        $data = [
            'name' => 'Friends',
            'slug' => 'friends',
            'server_type' => 'cx33',
            'location' => 'fsn1',
            'software' => 'custom',
            'start_command' => './run.sh',
            'java_version' => '21',
            'idle_minutes' => '15',
        ];

        $this->actingAs($admin)->put('/admin/servers/friends', [...$data, 'visibility' => 'link'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Visibility::Link, $server->refresh()->visibility);
        $this->assertNotNull($server->share_token);

        $this->actingAs($admin)->put('/admin/servers/friends', [...$data, 'visibility' => 'nope'])
            ->assertSessionHasErrors('visibility');

        // Left out (an older form): private.
        $this->actingAs($admin)->put('/admin/servers/friends', $data)->assertSessionHasNoErrors();
        $this->assertSame(Visibility::Private, $server->refresh()->visibility);
        $this->assertNull($server->share_token);
    }

    public function test_the_migration_keeps_what_each_server_had(): void
    {
        // Run the migration's down and up on real rows: public stays public, the rest private.
        $public = Server::factory()->public()->create();
        $private = Server::factory()->create();
        $link = $this->linkOnly();
        $migration = require database_path('migrations/2026_11_23_200000_add_visibility_to_servers.php');

        $migration->down();
        $this->assertSame([1, 0, 0], array_map(
            fn (Server $server) => (int) DB::table('servers')->where('id', $server->id)->value('is_public'),
            [$public, $private, $link],
        ));

        $migration->up();
        $this->assertSame(['public', 'private', 'private'], array_map(
            fn (Server $server) => DB::table('servers')->where('id', $server->id)->value('visibility'),
            [$public, $private, $link],
        ));
    }
}
