<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\User;
use App\Servers\ServerCatalog;
use App\Servers\ServerLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminServerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function valid(array $overrides = []): array
    {
        return [
            'name' => 'All The Mods 9',
            'slug' => 'atm9',
            'description' => 'Modpack, 1.20.1',
            'is_public' => '1',
            'icon_url' => '',
            'server_type' => 'cx33',
            'location' => 'fsn1',
            'start_command' => './run.sh',
            'java_version' => '17',
            'idle_minutes' => '15',
            ...$overrides,
        ];
    }

    public function test_players_cant_see_the_servers_admin(): void
    {
        $player = User::factory()->create();
        $server = Server::factory()->create();

        $this->actingAs($player)->get('/admin/servers')->assertNotFound();
        $this->actingAs($player)->get('/admin/servers/create')->assertNotFound();
        $this->actingAs($player)->post('/admin/servers', $this->valid())->assertNotFound();
        $this->actingAs($player)->post("/admin/servers/{$server->slug}/stop")->assertNotFound();
        $this->assertSame(1, Server::query()->count());
    }

    public function test_the_form_offers_the_providers_types_with_the_default_selected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/servers/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/servers/Create')
                ->where('catalog.defaults', ['server_type' => 'cx33', 'location' => 'fsn1'])
                ->has('catalog.types', 3)
                ->has('catalog.locations', 3)
                ->where('catalog.error', null)
                ->where('javaVersions', [17, 21, 25]));
    }

    public function test_the_default_is_the_cheapest_type_with_8_gb_in_germany(): void
    {
        $this->assertSame(
            ['server_type' => 'cx33', 'location' => 'fsn1'],
            $this->app->make(ServerCatalog::class)->defaults(),
        );
    }

    public function test_an_admin_creates_a_server(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/servers', $this->valid())
            ->assertRedirect('/admin/servers');

        $server = Server::query()->sole();
        $this->assertSame('atm9', $server->slug);
        $this->assertTrue($server->is_public);
        $this->assertNull($server->icon_url);
        $this->assertSame(17, $server->java_version);
        // Nothing exists at the provider until someone presses Start.
        $this->assertSame([], $this->app->make(CloudProvider::class)->managedServers());
    }

    public function test_the_slug_must_be_safe_and_unique(): void
    {
        $admin = User::factory()->admin()->create();
        Server::factory()->create(['slug' => 'taken']);

        foreach (['Has Spaces', 'semi;colon', 'a$(reboot)', '-leading', 'double--dash', 'taken'] as $slug) {
            $this->actingAs($admin)->post('/admin/servers', $this->valid(['slug' => $slug]))
                ->assertSessionHasErrors('slug');
        }

        // Upper case is lowered rather than refused.
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['slug' => 'ATM9']))->assertSessionHasNoErrors();
        $this->assertTrue(Server::query()->where('slug', 'atm9')->exists());
    }

    public function test_the_type_must_be_sold_in_the_location(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/servers', $this->valid(['server_type' => 'ccx23', 'location' => 'hel1']))
            ->assertSessionHasErrors(['server_type' => "CCX23 Dedicated isn't available in hel1."]);
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['server_type' => 'cx999']))
            ->assertSessionHasErrors('server_type');
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['location' => 'mars1']))
            ->assertSessionHasErrors('location');
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['java_version' => '8']))
            ->assertSessionHasErrors('java_version');
        $this->actingAs($admin)->post('/admin/servers', $this->valid(['icon_url' => 'http://example.com/a.png']))
            ->assertSessionHasErrors('icon_url');

        $this->assertSame(0, Server::query()->count());
    }

    public function test_the_start_command_must_be_one_line(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/servers', $this->valid(['start_command' => "./run.sh\nrm -rf /"]))
            ->assertSessionHasErrors(['start_command' => 'Keep the start command on one line.']);
    }

    public function test_an_admin_edits_a_server(): void
    {
        $admin = User::factory()->admin()->create();
        $server = Server::factory()->create(['slug' => 'vanilla']);

        $this->actingAs($admin)->get('/admin/servers/vanilla/edit')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('server.slug', 'vanilla'));

        $this->actingAs($admin)->put('/admin/servers/vanilla', $this->valid(['slug' => 'vanilla', 'name' => 'Vanilla 1.21']))
            ->assertRedirect('/admin/servers');

        $this->assertSame('Vanilla 1.21', $server->refresh()->name);
    }

    public function test_a_running_server_cant_be_deleted_until_stopped(): void
    {
        $admin = User::factory()->admin()->create();
        $server = Server::factory()->create(['name' => 'Vanilla', 'slug' => 'vanilla']);
        $run = $this->app->make(ServerLauncher::class)->start($server, $admin, null)->refresh();

        $this->actingAs($admin)->delete('/admin/servers/vanilla')
            ->assertInertiaFlash('toast.message', 'Stop Vanilla before deleting it.');
        $this->assertTrue(Server::query()->whereKey($server->id)->exists());

        $this->actingAs($admin)->post('/admin/servers/vanilla/stop')
            ->assertInertiaFlash('toast.type', 'success');

        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status);
        $this->assertNull($run->active_server_id);
        $this->assertNotNull($run->ended_at);
        /** @var FakeCloudProvider $cloud */
        $cloud = $this->app->make(CloudProvider::class);
        $this->assertNull($cloud->findServer((int) $run->provider_server_id));

        $this->actingAs($admin)->delete('/admin/servers/vanilla')->assertRedirect('/admin/servers');
        $this->assertFalse(Server::query()->whereKey($server->id)->exists());
    }
}
