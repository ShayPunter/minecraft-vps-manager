<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudProvider;
use App\Cloud\CloudServer;
use App\Cloud\FakeCloudProvider;
use App\Dns\Dns;
use App\Enums\RunStatus;
use App\Jobs\AdvanceActiveRuns;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Servers\FakeMinecraftProbe;
use App\Servers\FakeRemoteShell;
use App\Servers\MinecraftProbe;
use App\Servers\RemoteShell;
use App\Servers\ServerLauncher;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * Page views don't wait on the provider any more (#5752): they queue AdvanceActiveRuns,
 * and one run is advanced by one worker at a time.
 */
class AdvanceOffRequestTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    /** @var CountingCloud */
    private FakeCloudProvider $cloud;

    /** @var CountingProbe */
    private FakeMinecraftProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();

        $cache = $this->app->make(Repository::class);
        $this->cloud = new CountingCloud($cache);
        $this->probe = new CountingProbe($cache);
        $this->app->instance(CloudProvider::class, $this->cloud);
        $this->app->instance(MinecraftProbe::class, $this->probe);
    }

    private function bootingRun(Server $server): ServerRun
    {
        $run = $this->app->make(ServerLauncher::class)->start($server, User::factory()->create(), '198.51.100.1');
        $run->refresh();
        $this->assertSame(RunStatus::Booting, $run->status);
        // The VPS is up: the next check would move the run on (and point DNS, prepare it).
        $this->cloud->setStatus((int) $run->provider_server_id, 'running');
        $this->cloud->finds = 0;

        return $run;
    }

    private function shell(): FakeRemoteShell
    {
        $shell = $this->app->make(RemoteShell::class);
        $this->assertInstanceOf(FakeRemoteShell::class, $shell);

        return $shell;
    }

    public function test_page_views_queue_the_check_and_make_no_provider_dns_or_ssh_call(): void
    {
        $public = Server::factory()->public()->create(['slug' => 'pub']);
        $linked = Server::factory()->linkOnly()->create(['slug' => 'linked']);
        $runs = [$this->bootingRun($public), $this->bootingRun($linked)];
        $this->shell()->forget();
        $this->travel(11)->seconds();

        Queue::fake([AdvanceActiveRuns::class]);
        $dns = $this->spy(Dns::class);

        $this->get('/')->assertOk();
        $this->get('/s/'.$linked->share_token)->assertOk();
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();

        $dns->shouldNotHaveReceived('point');
        $this->assertSame(0, $this->cloud->finds);
        $this->assertSame(0, $this->probe->checks);
        $this->assertSame([], $this->shell()->commands());
        foreach ($runs as $run) {
            $this->assertSame(RunStatus::Booting, $run->refresh()->status);
        }

        // Three views, one job: it's unique while queued.
        Queue::assertPushed(AdvanceActiveRuns::class, 1);
    }

    public function test_the_queued_job_moves_the_run_on(): void
    {
        $run = $this->bootingRun(Server::factory()->create());
        $this->travel(11)->seconds();

        (new AdvanceActiveRuns)->handle($this->app->make(ServerLauncher::class));

        $this->assertSame(1, $this->cloud->finds);
        $this->assertSame(RunStatus::Online, $run->refresh()->status);
    }

    public function test_nothing_is_queued_while_no_run_is_due(): void
    {
        Queue::fake([AdvanceActiveRuns::class]);
        $server = Server::factory()->public()->create();

        $this->get('/')->assertOk();
        Queue::assertNothingPushed();

        // Just checked (provisioned this second): not due until the interval has passed.
        $this->bootingRun($server);
        $this->get('/')->assertOk();
        Queue::assertNothingPushed();

        $this->travel(11)->seconds();
        $this->get('/')->assertOk();
        Queue::assertPushed(AdvanceActiveRuns::class, 1);
    }

    public function test_a_second_worker_skips_a_run_that_is_being_checked(): void
    {
        $run = $this->bootingRun(Server::factory()->create());
        $this->travel(11)->seconds();
        $launcher = $this->app->make(ServerLauncher::class);

        // While the first check waits on Hetzner, another worker (a second poll's job, or
        // servers:watch) advances too: it must not ask Hetzner again.
        $this->cloud->during = function () use ($launcher): void {
            $this->cloud->during = null;
            $launcher->advanceActive();
            $launcher->advanceActive(force: true);
        };
        $launcher->advanceActive();

        $this->assertSame(1, $this->cloud->finds);
        $this->assertSame(RunStatus::Online, $run->refresh()->status);
    }

    public function test_a_worker_that_read_the_run_before_another_claimed_it_skips_it(): void
    {
        $run = $this->bootingRun(Server::factory()->create());
        $this->travel(11)->seconds();
        $launcher = $this->app->make(ServerLauncher::class);

        // The second worker read the due run before the first one claimed it, and gets the
        // lock after the first let go (forced here): the claim on checked_at stops it.
        $this->cloud->during = function () use ($launcher, $run): void {
            $this->cloud->during = null;
            $this->cloud->setStatus((int) $run->provider_server_id, 'initializing');
            Cache::lock("mvm:advance:{$run->id}")->forceRelease();
            $launcher->advanceActive();
        };
        $launcher->advanceActive();

        $this->assertSame(1, $this->cloud->finds);
    }

    public function test_servers_watch_still_advances_on_its_own(): void
    {
        $run = $this->bootingRun(Server::factory()->create());

        // No interval wait: the scheduler forces the check.
        $this->artisan('servers:watch')->assertSuccessful();

        $this->assertSame(1, $this->cloud->finds);
        $this->assertSame(RunStatus::Online, $run->refresh()->status);
    }

    public function test_a_run_that_stopped_after_the_list_was_read_is_left_alone(): void
    {
        $run = $this->bootingRun(Server::factory()->create());
        $other = $this->bootingRun(Server::factory()->create());
        $this->travel(11)->seconds();

        // The first run's check stops the second one (as an admin's stop would) before the
        // loop gets to it: the stale copy in the list must not be advanced or saved back.
        $this->cloud->during = function () use ($other): void {
            $this->cloud->during = null;
            ServerRun::query()->whereKey($other->id)->update(['status' => RunStatus::Stopping]);
        };
        $this->app->make(ServerLauncher::class)->advanceActive();

        $this->assertSame(1, $this->cloud->finds);
        $this->assertSame(RunStatus::Online, $run->refresh()->status);
        $this->assertSame(RunStatus::Stopping, $other->refresh()->status);
    }
}

class CountingCloud extends FakeCloudProvider
{
    public int $finds = 0;

    public ?Closure $during = null;

    public function findServer(int $id): ?CloudServer
    {
        $this->finds++;

        if ($this->during !== null) {
            ($this->during)();
        }

        return parent::findServer($id);
    }
}

class CountingProbe extends FakeMinecraftProbe
{
    public int $checks = 0;

    public function acceptsConnections(string $host, int $port): bool
    {
        $this->checks++;

        return parent::acceptsConnections($host, $port);
    }
}
