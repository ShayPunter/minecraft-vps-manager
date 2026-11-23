<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Cloud\CloudServer;
use App\Cloud\FakeCloudProvider;
use App\Cloud\ServerSpec;
use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Servers\FakeMinecraftProbe;
use App\Servers\MinecraftProbe;
use App\Servers\ServerLauncher;
use App\Servers\ServerPresenter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

class ServerStartTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();
    }

    private function fake(): FakeCloudProvider
    {
        $cloud = $this->app->make(CloudProvider::class);
        $this->assertInstanceOf(FakeCloudProvider::class, $cloud);

        return $cloud;
    }

    public function test_a_player_starts_a_server_and_it_reaches_online(): void
    {
        $player = User::factory()->create();
        $server = Server::factory()->create(['name' => 'Vanilla', 'slug' => 'vanilla']);

        $this->actingAs($player)
            ->from('/dashboard')
            ->post('/servers/vanilla/start')
            ->assertRedirect('/dashboard')
            ->assertInertiaFlash('toast.type', 'success');

        $run = ServerRun::query()->sole();
        $this->assertSame(RunStatus::Booting, $run->status);
        $this->assertSame($server->id, $run->active_server_id);
        $this->assertSame($player->id, $run->started_by);
        $this->assertNotNull($run->provider_server_id);

        $vps = $this->fake()->rawServer($run->provider_server_id);
        $this->assertNotNull($vps);
        $this->assertSame('mvm-vanilla-'.$run->id, $vps['name']);
        $this->assertSame('cx33', $vps['server_type']);
        $this->assertSame('fsn1', $vps['location']);
        $this->assertSame((string) $server->id, $vps['labels']['mvm-server-id']);
        $this->assertStringStartsWith("#cloud-config\n", $vps['user_data']);

        // Still booting: the dashboard says so.
        $this->actingAs($player)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('servers.0.status', 'booting')
                ->where('servers.0.can_start', false)
                ->where('servers.0.address', null));

        // The VPS boots and Minecraft answers: the next check (after the interval) marks it online.
        $this->fake()->setStatus($run->provider_server_id, 'running');
        $this->travel(11)->seconds();

        $this->actingAs($player)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('servers.0.status', 'online')
                ->where('servers.0.address', $run->refresh()->ipv4));

        $this->assertNotNull($run->online_at);
    }

    public function test_it_waits_in_starting_until_minecraft_answers(): void
    {
        $server = Server::factory()->create();
        /** @var FakeMinecraftProbe $probe */
        $probe = $this->app->make(MinecraftProbe::class);
        $probe->refuse();

        $run = $this->app->make(ServerLauncher::class)->start($server, null, '198.51.100.1');
        $run->refresh();
        $this->fake()->setStatus((int) $run->provider_server_id, 'running');

        $this->artisan('servers:advance')->assertSuccessful();
        $this->assertSame(RunStatus::Starting, $run->refresh()->status);

        $probe->refuse(false);
        $this->artisan('servers:advance')->assertSuccessful();
        $this->assertSame(RunStatus::Online, $run->refresh()->status);
    }

    public function test_a_second_start_is_refused_and_makes_no_second_vps(): void
    {
        $player = User::factory()->create();
        Server::factory()->create(['name' => 'Vanilla', 'slug' => 'vanilla']);

        $this->actingAs($player)->post('/servers/vanilla/start');
        $this->actingAs($player)->post('/servers/vanilla/start')
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Vanilla is already running or starting.']);

        $this->assertSame(1, ServerRun::query()->count());
        $this->assertCount(1, $this->fake()->managedServers());
    }

    public function test_the_database_refuses_two_active_runs_for_one_server(): void
    {
        $server = Server::factory()->create();

        foreach ([1, 2] as $attempt) {
            $run = new ServerRun;
            $run->server_id = $server->id;
            $run->active_server_id = $server->id;
            $run->status = RunStatus::Provisioning;

            if ($attempt === 2) {
                $this->expectException(UniqueConstraintViolationException::class);
            }

            $run->save();
        }
    }

    public function test_the_cap_stops_a_third_server(): void
    {
        $player = User::factory()->create();
        [$a, $b, $c] = Server::factory()->count(3)->create();

        $this->actingAs($player)->post("/servers/{$a->slug}/start");
        $this->actingAs($player)->post("/servers/{$b->slug}/start");
        $this->actingAs($player)->post("/servers/{$c->slug}/start")
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', '2 servers are already running, the most allowed at once. Try again when one has stopped.');

        $this->assertSame(2, ServerRun::query()->active()->count());
        $this->assertFalse($c->activeRun()->exists());
        $this->assertCount(2, $this->fake()->managedServers());
    }

    public function test_a_guest_cannot_start_a_private_server(): void
    {
        Server::factory()->create(['slug' => 'friends-only']);

        $this->post('/servers/friends-only/start')->assertNotFound();

        $this->assertSame(0, ServerRun::query()->count());
        $this->assertSame([], $this->fake()->managedServers());
    }

    public function test_a_guest_can_start_a_public_server(): void
    {
        $server = Server::factory()->public()->create(['slug' => 'open-world']);

        $this->from('/')->post('/servers/open-world/start', [], ['REMOTE_ADDR' => '198.51.100.9'])
            ->assertRedirect('/')
            ->assertInertiaFlash('toast.type', 'success');

        $run = ServerRun::query()->sole();
        $this->assertSame($server->id, $run->server_id);
        $this->assertNull($run->started_by);
        $this->assertSame('198.51.100.9', $run->started_from_ip);

        // The home page lists it with its status.
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Welcome')
            ->has('servers', 1)
            ->where('servers.0.slug', 'open-world')
            ->where('servers.0.status', 'booting'));
    }

    public function test_the_home_page_lists_only_public_servers(): void
    {
        Server::factory()->public()->create(['slug' => 'open-world']);
        Server::factory()->create(['slug' => 'friends-only']);

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('servers', 1)
            ->where('servers.0.slug', 'open-world'));
    }

    public function test_starting_is_rate_limited(): void
    {
        Server::factory()->public()->create(['slug' => 'open-world']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/servers/open-world/start')->assertRedirect();
        }

        $this->post('/servers/open-world/start')->assertTooManyRequests();
    }

    public function test_start_is_post_only_and_the_old_provision_url_is_gone(): void
    {
        Server::factory()->public()->create(['slug' => 'open-world']);

        $this->get('/servers/open-world/start')->assertMethodNotAllowed();
        $this->get('/server/provision/open-world')->assertNotFound();
        $this->assertSame(0, ServerRun::query()->count());
    }

    public function test_a_provider_error_leaves_the_server_stopped_with_the_error_shown(): void
    {
        $this->app->instance(CloudProvider::class, new class($this->app->make(Repository::class)) extends FakeCloudProvider
        {
            public function createServer(ServerSpec $spec): CloudServer
            {
                throw new CloudException('resource_limit_exceeded', 'server limit reached for this project');
            }
        });

        $player = User::factory()->create();
        $server = Server::factory()->create(['name' => 'Vanilla', 'slug' => 'vanilla']);

        $this->actingAs($player)->post('/servers/vanilla/start')
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', "Vanilla couldn't start. ".ServerPresenter::PUBLIC_ERROR);

        $run = ServerRun::query()->sole();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertNull($run->active_server_id);
        $this->assertStringContainsString('server limit reached', (string) $run->error);
        $this->assertFalse($server->activeRun()->exists());

        // The provider's message is for admins (#5527); a player gets the label and a plain line.
        $this->actingAs($player)->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('servers.0.status', 'stopped')
                ->where('servers.0.can_start', true)
                ->where('servers.0.error_label', 'Last start failed')
                ->where('servers.0.error', ServerPresenter::PUBLIC_ERROR));

        $this->actingAs(User::factory()->admin()->create())->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('servers.0.error', 'The cloud provider refused to create the VPS: server limit reached for this project'));

        // It can be started again.
        $this->app->forgetInstance(CloudProvider::class);
    }

    public function test_guests_never_see_a_failed_starts_details(): void
    {
        $this->app->instance(CloudProvider::class, new class($this->app->make(Repository::class)) extends FakeCloudProvider
        {
            public function createServer(ServerSpec $spec): CloudServer
            {
                throw new CloudException('error', 'root@203.0.113.5 said no');
            }
        });
        Server::factory()->public()->create(['name' => 'Open', 'slug' => 'open-world']);

        $this->from('/')->post('/servers/open-world/start')
            ->assertInertiaFlash('toast.message', "Open couldn't start. ".ServerPresenter::PUBLIC_ERROR);

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('servers.0.error_label', 'Last start failed')
            ->where('servers.0.error', ServerPresenter::PUBLIC_ERROR));
        $this->assertStringNotContainsString('203.0.113.5', (string) $this->get('/')->getContent());

        $this->app->forgetInstance(CloudProvider::class);
    }

    public function test_a_vps_that_disappears_while_booting_fails_the_run(): void
    {
        $server = Server::factory()->create();
        $run = $this->app->make(ServerLauncher::class)->start($server, null, null)->refresh();
        $this->fake()->deleteServer((int) $run->provider_server_id);

        $this->artisan('servers:advance')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertNull($run->active_server_id);
    }
}
