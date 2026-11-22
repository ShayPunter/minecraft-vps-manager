<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Notifications\ServerAlert;
use App\Servers\CloudInit;
use App\Servers\FakeMinecraftProbe;
use App\Servers\FakeRemoteShell;
use App\Servers\MinecraftProbe;
use App\Servers\RemoteShell;
use App\Servers\RunLog;
use App\Servers\ServerLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * A start whose Minecraft keeps crashing is given up within a minute or so, not after the
 * 20-minute boot timeout (#5376). The log is Shay's from the card: a heap too big for the type.
 */
class CrashLoopTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
    }

    private function cloud(): FakeCloudProvider
    {
        $cloud = $this->app->make(CloudProvider::class);
        $this->assertInstanceOf(FakeCloudProvider::class, $cloud);

        return $cloud;
    }

    private function shell(): FakeRemoteShell
    {
        $shell = $this->app->make(RemoteShell::class);
        $this->assertInstanceOf(FakeRemoteShell::class, $shell);

        return $shell;
    }

    /**
     * Started, set up, but Minecraft's port never opens.
     */
    private function starting(): ServerRun
    {
        $probe = $this->app->make(MinecraftProbe::class);
        $this->assertInstanceOf(FakeMinecraftProbe::class, $probe);
        $probe->refuse();

        $server = Server::factory()->create(['name' => 'Test Server', 'slug' => 'test-server']);
        $run = $this->app->make(ServerLauncher::class)->start($server, null, null)->refresh();
        $this->cloud()->setStatus((int) $run->provider_server_id, 'running');
        $this->artisan('servers:watch')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Starting, $run->status);
        $this->assertNotNull($run->prepared_at);
        $this->shell()->forget();

        return $run;
    }

    private function crash(ServerRun $run, int $counter): void
    {
        $t = now()->toIso8601String();
        $this->app->make(RunLog::class)->append($run, 'minecraft', implode("\n", [
            "{$t} mvm-test-server-{$run->id} systemd[1]: minecraft-test-server.service: Scheduled restart job, restart counter is at {$counter}.",
            "{$t} mvm-test-server-{$run->id} systemd[1]: Started minecraft-test-server.service - Minecraft server test-server.",
            "{$t} mvm-test-server-{$run->id} mvm-start[9895]: OpenJDK 64-Bit Server VM warning: INFO: os::commit_memory(0x00000006c0000000, 5368709120, 0) failed; error='Not enough space' (errno=12)",
            "{$t} mvm-test-server-{$run->id} mvm-start[9895]: # There is insufficient memory for the Java Runtime Environment to continue.",
            "{$t} mvm-test-server-{$run->id} systemd[1]: minecraft-test-server.service: Main process exited, code=exited, status=1/FAILURE",
        ]));
    }

    public function test_a_start_that_keeps_crashing_is_given_up_with_its_cause(): void
    {
        $run = $this->starting();
        $vpsId = (int) $run->provider_server_id;

        $this->crash($run, 1);
        $this->crash($run, 2);
        $this->travel(1)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();
        $this->assertSame(RunStatus::Starting, $run->refresh()->status, 'Two restarts: still given a chance.');

        $this->crash($run, 3);
        $this->travel(1)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('crash_loop', $run->stop_reason);
        $this->assertNull($run->active_server_id);
        $this->assertStringStartsWith('Minecraft crashed and was restarted 3 times while starting, so the VPS was deleted instead of waiting for the boot timeout.', (string) $run->error);
        $this->assertStringContainsString("Likely cause: Java couldn't get the memory the start command asks for", (string) $run->error);
        $this->assertNull($this->cloud()->findServer($vpsId), 'The VPS is gone.');
        Notification::assertSentTo(new AnonymousNotifiable, ServerAlert::class, fn (ServerAlert $alert) => str_contains($alert->subject, "Test Server didn't start"));
        Notification::assertSentTimes(ServerAlert::class, 1);
    }

    public function test_a_slow_start_without_crashes_gets_the_whole_boot_timeout(): void
    {
        $run = $this->starting();
        $this->app->make(RunLog::class)->append($run, 'minecraft', 'mvm-start[1]: [Worker-Main-1/INFO]: Preparing spawn area: 40%');

        $this->travel(15)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();

        $this->assertSame(RunStatus::Starting, $run->refresh()->status);
        $this->assertSame([], $this->shell()->commands(), 'Its log arrives, so systemd isn\'t asked over SSH.');

        $this->travel(6)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('boot_timeout', $run->stop_reason);
    }

    public function test_without_its_log_systemd_is_asked_over_ssh_once_a_minute(): void
    {
        $run = $this->starting();
        $command = CloudInit::restartsCommand($run->server);
        $this->assertSame('systemctl show -p NRestarts --value minecraft-test-server.service', $command);

        // Right after setup, nothing is asked yet: the shipper may simply not have sent anything.
        $this->artisan('servers:watch')->assertSuccessful();
        $this->assertNotContains($command, array_column($this->shell()->commands(), 'command'));

        $this->shell()->failMatching('NRestarts', 0, "1\n");
        $this->travel(2)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();
        $this->app->make(ServerLauncher::class)->advance($run->refresh());
        $this->assertSame(1, collect($this->shell()->commands())->where('command', $command)->count(), 'Once a minute, not on every check.');
        $this->assertSame(RunStatus::Starting, $run->refresh()->status);

        $this->shell()->succeed();
        $this->shell()->failMatching('NRestarts', 0, "4\n");
        $this->travel(1)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringStartsWith('Minecraft crashed and was restarted 4 times', (string) $run->error);
    }

    public function test_an_unreachable_vps_is_left_to_the_boot_timeout(): void
    {
        $run = $this->starting();
        $this->shell()->unreachable('Connection refused');

        $this->travel(2)->minutes();
        $this->artisan('servers:watch')->assertSuccessful();

        $this->assertSame(RunStatus::Starting, $run->refresh()->status);
    }
}
