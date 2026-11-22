<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudProvider;
use App\Cloud\CloudServer;
use App\Cloud\FakeCloudProvider;
use App\Cloud\ServerSpec;
use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Notifications\ServerAlert;
use App\Servers\FakeMinecraftProbe;
use App\Servers\FakeRemoteShell;
use App\Servers\MinecraftProbe;
use App\Servers\RemoteShell;
use App\Servers\RunLog;
use App\Servers\ServerLauncher;
use App\Servers\WorldSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

class WatchdogTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeWorldStorage();

        Notification::fake();
        config(['minecraft.alert_mail' => 'ops@example.com']);
        $this->travelTo(now()->startOfMinute());
    }

    private function cloud(): FakeCloudProvider
    {
        $cloud = $this->app->make(CloudProvider::class);
        $this->assertInstanceOf(FakeCloudProvider::class, $cloud);

        return $cloud;
    }

    private function probe(): FakeMinecraftProbe
    {
        $probe = $this->app->make(MinecraftProbe::class);
        $this->assertInstanceOf(FakeMinecraftProbe::class, $probe);

        return $probe;
    }

    private function shell(): FakeRemoteShell
    {
        $shell = $this->app->make(RemoteShell::class);
        $this->assertInstanceOf(FakeRemoteShell::class, $shell);

        return $shell;
    }

    private function watch(): void
    {
        $this->artisan('servers:watch')->assertSuccessful();
    }

    private function sweep(): void
    {
        $this->artisan('servers:sweep')->assertSuccessful();
    }

    /**
     * Start a server and bring it online, as the scheduler would.
     */
    private function online(array $attributes = [], ?User $starter = null): ServerRun
    {
        $server = Server::factory()->create(['name' => 'Vanilla', 'slug' => 'vanilla', ...$attributes]);
        $run = $this->app->make(ServerLauncher::class)->start($server, $starter, null)->refresh();
        $this->cloud()->setStatus((int) $run->provider_server_id, 'running');
        $this->watch();

        $run->refresh();
        $this->assertSame(RunStatus::Online, $run->status);
        // The start ran prepare.sh (#5288); the tests below look at the stop.
        $this->shell()->forget();

        return $run;
    }

    private function assertAlerted(string $subjectContains): void
    {
        Notification::assertSentTo(
            new AnonymousNotifiable,
            ServerAlert::class,
            fn (ServerAlert $alert) => str_contains($alert->subject, $subjectContains),
        );
    }

    public function test_an_idle_server_is_saved_then_deleted_after_its_idle_minutes(): void
    {
        $run = $this->online(['idle_minutes' => 15]);
        $vpsId = (int) $run->provider_server_id;

        $this->travel(14)->minutes();
        $this->watch();
        $this->assertSame(RunStatus::Online, $run->refresh()->status);
        $this->assertSame(0, $run->players_online);
        $this->assertSame([], $this->shell()->commands());

        $this->travel(1)->minutes();
        $this->watch();

        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status);
        $this->assertSame('idle', $run->stop_reason);
        $this->assertNull($run->active_server_id);
        $this->assertNull($this->cloud()->findServer($vpsId));

        // Minecraft was stopped cleanly over SSH, then the world was uploaded (#5288), and
        // only then was the VPS deleted.
        $commands = $this->shell()->commands();
        $this->assertCount(2, $commands);
        $this->assertSame($run->ipv4, $commands[0]['host']);
        $this->assertStringStartsWith('systemctl stop minecraft-vanilla.service; ', $commands[0]['command']);
        $this->assertStringContainsString('sync;', $commands[0]['command']);
        $this->assertStringStartsWith(WorldSync::SAVE_COMMAND." 'vanilla/", $commands[1]['command']);
        $this->assertNotNull($run->saved_world_archive_id);
        $this->assertSame($run->saved_world_archive_id, $run->server->refresh()->current_world_archive_id);
        Notification::assertNothingSent();
    }

    public function test_boot_time_doesnt_count_as_idle(): void
    {
        $server = Server::factory()->create(['slug' => 'slow', 'idle_minutes' => 15]);
        $run = $this->app->make(ServerLauncher::class)->start($server, null, null)->refresh();
        $this->cloud()->setStatus((int) $run->provider_server_id, 'running');
        $this->probe()->refuse();

        // 12 minutes of installing Java and generating the world.
        $this->travel(12)->minutes();
        $this->watch();
        $this->assertSame(RunStatus::Starting, $run->refresh()->status);

        $this->probe()->refuse(false);
        $this->watch();
        $this->assertSame(RunStatus::Online, $run->refresh()->status);

        // Idle time starts now, not at Start.
        $this->travel(10)->minutes();
        $this->watch();
        $this->assertSame(RunStatus::Online, $run->refresh()->status);
    }

    public function test_players_keep_it_running_and_idle_time_restarts_when_they_leave(): void
    {
        $run = $this->online(['idle_minutes' => 15]);

        $this->probe()->setPlayers(3);
        $this->travel(40)->minutes();
        $this->watch();
        $this->assertSame(RunStatus::Online, $run->refresh()->status);
        $this->assertSame(3, $run->players_online);

        $this->probe()->setPlayers(0);
        $this->travel(14)->minutes();
        $this->watch();
        $this->assertSame(RunStatus::Online, $run->refresh()->status);

        $this->travel(1)->minutes();
        $this->watch();
        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
    }

    public function test_the_idle_minutes_are_set_per_server(): void
    {
        $run = $this->online(['idle_minutes' => 5]);

        $this->travel(5)->minutes();
        $this->watch();

        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
    }

    public function test_a_failed_save_keeps_the_vps_and_alerts_once(): void
    {
        $run = $this->online(['idle_minutes' => 15]);
        $vpsId = (int) $run->provider_server_id;
        $this->shell()->failWith(1, 'result=timeout');

        $this->travel(15)->minutes();
        $this->watch();

        $run->refresh();
        $this->assertSame(RunStatus::StopFailed, $run->status);
        $this->assertNotNull($run->active_server_id);
        $this->assertNotNull($this->cloud()->findServer($vpsId), 'The VPS must be kept when the world could not be saved.');
        $this->assertStringContainsString("Minecraft didn't stop cleanly (exit 1: result=timeout)", (string) $run->error);
        $this->assertAlerted("Vanilla couldn't be stopped");
        Notification::assertSentTimes(ServerAlert::class, 1);

        // Still counts towards the cap: its VPS still costs money.
        $this->assertTrue(RunStatus::StopFailed->isActive());

        // The sweep retries; still failing, no second alert.
        $this->sweep();
        $this->assertSame(RunStatus::StopFailed, $run->refresh()->status);
        Notification::assertSentTimes(ServerAlert::class, 1);

        // Once the save works, the next retry finishes the stop.
        $this->shell()->succeed();
        $this->sweep();
        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
        $this->assertNull($this->cloud()->findServer($vpsId));
    }

    public function test_an_unreachable_vps_is_kept(): void
    {
        $run = $this->online();
        $this->shell()->unreachable('Connection refused');

        $this->travel(15)->minutes();
        $this->watch();

        $run->refresh();
        $this->assertSame(RunStatus::StopFailed, $run->status);
        $this->assertStringContainsString("Couldn't reach the VPS to save the world: Connection refused", (string) $run->error);
        $this->assertNotNull($this->cloud()->findServer((int) $run->provider_server_id));
    }

    public function test_alerts_go_to_the_configured_address_and_discord(): void
    {
        config([
            'minecraft.alert_mail' => 'alerts@example.com',
            'minecraft.alert_discord_webhook' => 'https://discord.example/api/webhooks/1/x',
        ]);
        Http::fake(['discord.example/*' => Http::response(null, 204)]);
        $run = $this->online();
        $this->shell()->unreachable();

        $this->travel(15)->minutes();
        $this->watch();

        Notification::assertSentOnDemand(ServerAlert::class, fn (ServerAlert $alert, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === ['alerts@example.com']);
        Http::assertSent(fn ($request) => $request->url() === 'https://discord.example/api/webhooks/1/x'
            && str_contains((string) $request['content'], "Vanilla couldn't be stopped")
            && $request['allowed_mentions'] === ['parse' => []]);
        $this->assertSame(RunStatus::StopFailed, $run->refresh()->status);
    }

    public function test_without_an_alert_address_every_admin_is_mailed(): void
    {
        config(['minecraft.alert_mail' => null]);
        User::factory()->admin()->create(['email' => 'one@example.com']);
        User::factory()->admin()->create(['email' => 'two@example.com']);
        User::factory()->create(['email' => 'player@example.com']);
        $this->online();
        $this->shell()->unreachable();

        $this->travel(15)->minutes();
        $this->watch();

        Notification::assertSentOnDemand(ServerAlert::class, function (ServerAlert $alert, array $channels, AnonymousNotifiable $notifiable) {
            $to = $notifiable->routes['mail'];
            sort($to);

            return $to === ['one@example.com', 'two@example.com'];
        });
    }

    public function test_a_failed_delete_after_a_good_save_keeps_the_run_as_stop_failed(): void
    {
        $run = $this->online();
        // Hetzner refuses the delete: here, because the VPS lost its managed-by label.
        $this->cloud()->reset();
        $unmanaged = $this->cloud()->addUnmanagedServer('mvm-vanilla-'.$run->id);
        $run->provider_server_id = $unmanaged->id;
        $run->save();

        $this->travel(15)->minutes();
        $this->watch();

        $run->refresh();
        $this->assertSame(RunStatus::StopFailed, $run->status);
        $this->assertStringContainsString("The world was saved, but the VPS couldn't be deleted", (string) $run->error);
        $this->assertNotNull($this->cloud()->findServer($unmanaged->id));
    }

    public function test_a_server_that_never_comes_online_is_deleted_after_20_minutes(): void
    {
        $server = Server::factory()->create(['name' => 'Broken', 'slug' => 'broken']);
        $run = $this->app->make(ServerLauncher::class)->start($server, null, null)->refresh();
        $vpsId = (int) $run->provider_server_id;
        $this->cloud()->setStatus($vpsId, 'running');
        $this->probe()->refuse();

        $this->travel(19)->minutes();
        $this->watch();
        $this->assertSame(RunStatus::Starting, $run->refresh()->status);

        $this->travel(1)->minutes();
        $this->watch();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('boot_timeout', $run->stop_reason);
        $this->assertNull($run->active_server_id);
        $this->assertStringContainsString("wasn't online within 20 minutes", (string) $run->error);
        $this->assertNull($this->cloud()->findServer($vpsId));
        // Never online: prepare.sh ran, but there was no world to save. Before the VPS was
        // deleted, the app pulled its last log lines (#5367).
        $this->assertSame([WorldSync::PREPARE_COMMAND, RunLog::fallbackCommand($server)], array_column($this->shell()->commands(), 'command'));
        $this->assertAlerted("Broken didn't start");
    }

    public function test_the_sweep_deletes_vpses_no_active_run_knows_about(): void
    {
        $run = $this->online();
        $cloud = $this->cloud();
        $key = $cloud->createSshKey('mvm-old-999', 'ssh-ed25519 AAAA mvm-old-999');
        $orphan = $cloud->createServer(new ServerSpec('mvm-old-999', 'cx33', 'fsn1', 1, [$key->id], userData: '#cloud-config'));
        // Created by a start whose run id isn't saved on the run yet (provisioning): kept.
        $server = Server::factory()->create(['slug' => 'other']);
        $provisioning = new ServerRun;
        $provisioning->server_id = $server->id;
        $provisioning->active_server_id = $server->id;
        $provisioning->status = RunStatus::Provisioning;
        $provisioning->save();
        $inFlight = $cloud->createServer(new ServerSpec("mvm-other-{$provisioning->id}", 'cx33', 'fsn1', $server->id, [$key->id], userData: '#cloud-config'));
        $handMade = $cloud->addUnmanagedServer('shays-own-box');

        $this->sweep();

        $this->assertNull($cloud->findServer($orphan->id));
        $this->assertNotNull($cloud->findServer((int) $run->provider_server_id));
        $this->assertNotNull($cloud->findServer($inFlight->id));
        $this->assertNotNull($cloud->findServer($handMade->id), 'Never touch a VPS the app did not create.');
        $this->assertAlerted('Deleted VPSes no server was using');
    }

    public function test_a_very_long_run_is_alerted_once(): void
    {
        $run = $this->online();
        $this->probe()->setPlayers(2);

        $this->travel(7)->hours();
        $this->watch();
        $this->sweep();
        Notification::assertNothingSent();

        $this->travel(1)->hours();
        $this->watch();
        $this->sweep();
        $this->assertAlerted('Vanilla has been running for 8 hours');
        $this->assertNotNull($run->refresh()->long_run_alerted_at);

        $this->travel(10)->minutes();
        $this->sweep();
        Notification::assertSentTimes(ServerAlert::class, 1);
        $this->assertSame(RunStatus::Online, $run->refresh()->status);
    }

    public function test_a_stop_whose_job_never_finished_becomes_stop_failed_and_is_retried(): void
    {
        $run = $this->online();
        $run->status = RunStatus::Stopping;
        $run->save();

        // stuck_stop_minutes is 50: above StopServerRun's 45-minute timeout (#5288).
        $this->travel(49)->minutes();
        $this->sweep();
        $this->assertSame(RunStatus::Stopping, $run->refresh()->status, 'A long upload is still running.');

        $this->travel(2)->minutes();
        $this->sweep();

        // Marked stop_failed (alerted), then retried in the same sweep, which works.
        $this->assertAlerted("Vanilla couldn't be stopped");
        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
    }

    public function test_a_job_that_runs_after_its_run_ended_creates_nothing(): void
    {
        $server = Server::factory()->create(['slug' => 'late']);
        $run = new ServerRun;
        $run->server_id = $server->id;
        $run->active_server_id = null;
        $run->status = RunStatus::Failed;
        $run->save();
        // The caller still holds an old copy that says provisioning: the database wins.
        $run->status = RunStatus::Provisioning;
        $this->app->make(ServerLauncher::class)->provision($run);

        $this->assertSame([], $this->cloud()->managedServers());
        $this->assertSame([], $this->cloud()->managedSshKeys());
        $this->assertSame(RunStatus::Failed, $run->refresh()->status);
    }

    public function test_a_vps_created_after_its_run_was_stopped_is_deleted_at_once(): void
    {
        // The run ends (a stop, a boot timeout) while the provider is still creating the VPS.
        $this->app->instance(CloudProvider::class, new class($this->app->make('cache.store')) extends FakeCloudProvider
        {
            public function createServer(ServerSpec $spec): CloudServer
            {
                ServerRun::query()->update(['status' => RunStatus::Failed, 'active_server_id' => null]);

                return parent::createServer($spec);
            }
        });
        $server = Server::factory()->create(['slug' => 'late']);
        $run = $this->app->make(ServerLauncher::class)->start($server, null, null)->refresh();

        $this->assertSame([], $this->cloud()->managedServers());
        $this->assertSame([], $this->cloud()->managedSshKeys(), 'Its key goes with it.');
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertNull($run->ssh_private_key);
        $this->assertNull($run->provider_ssh_key_id);
    }

    public function test_the_starter_and_admins_can_stop_others_cant(): void
    {
        $starter = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $run = $this->online([], $starter);

        $this->actingAs($other)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('servers.0.can_stop', false));
        $this->actingAs($other)->post('/servers/vanilla/stop')->assertNotFound();
        $this->assertSame(RunStatus::Online, $run->refresh()->status);

        $this->actingAs($starter)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('servers.0.can_stop', true)
            ->where('servers.0.players_online', 0)
            ->where('servers.0.can_force_delete', false));
        $this->actingAs($starter)->from('/dashboard')->post('/servers/vanilla/stop')
            ->assertRedirect('/dashboard')
            ->assertInertiaFlash('toast.message', 'Stopped Vanilla. The world is saved and the VPS is deleted.');

        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status);
        $this->assertSame('starter', $run->stop_reason);
        $this->assertCount(2, $this->shell()->commands(), 'systemctl stop, then save.sh.');

        $this->assertSame('admin', $this->stopAsAdminAndGetReason($admin));
    }

    private function stopAsAdminAndGetReason(User $admin): string
    {
        $run = $this->app->make(ServerLauncher::class)->start(Server::query()->sole(), null, null)->refresh();
        $this->cloud()->setStatus((int) $run->provider_server_id, 'running');
        $this->watch();

        $this->actingAs($admin)->post('/servers/vanilla/stop')->assertRedirect();

        return (string) $run->refresh()->stop_reason;
    }

    public function test_guests_cant_stop_and_a_guest_started_run_is_stopped_by_admins_only(): void
    {
        $this->online(['is_public' => true]);

        $this->post('/servers/vanilla/stop')->assertRedirect('/login');
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('servers.0.can_stop', false));
    }

    public function test_an_admin_can_delete_without_saving_only_after_a_failed_stop(): void
    {
        $admin = User::factory()->admin()->create();
        $run = $this->online();
        $vpsId = (int) $run->provider_server_id;

        // Not offered, and refused, while the server is fine.
        $this->actingAs($admin)->post('/admin/servers/vanilla/stop', ['force' => '1'])
            ->assertInertiaFlash('toast.type', 'info');
        $this->assertSame(RunStatus::Online, $run->refresh()->status);

        $this->shell()->unreachable();
        $this->actingAs($admin)->post('/admin/servers/vanilla/stop')
            ->assertInertiaFlash('toast.type', 'error');
        $this->assertSame(RunStatus::StopFailed, $run->refresh()->status);

        $this->actingAs($admin)->get('/admin/servers')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('servers.0.status', 'stop_failed')
            ->where('servers.0.error_label', 'Stop failed')
            ->where('servers.0.can_stop', true)
            ->where('servers.0.can_force_delete', true));

        $commandsBefore = count($this->shell()->commands());
        $this->actingAs($admin)->post('/admin/servers/vanilla/stop', ['force' => '1'])
            ->assertInertiaFlash('toast.message', 'Deleted the VPS of Vanilla without saving the world.');

        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
        $this->assertNull($this->cloud()->findServer($vpsId));
        $this->assertCount($commandsBefore, $this->shell()->commands(), 'A forced delete skips the save.');
    }

    public function test_the_scheduler_runs_the_watchdog(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('servers:watch')
            ->expectsOutputToContain('servers:sweep')
            ->assertSuccessful();
    }
}
