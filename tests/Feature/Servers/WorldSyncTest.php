<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Models\WorldArchive;
use App\Models\WorldStorageSetting;
use App\Notifications\ServerAlert;
use App\Servers\FakeMinecraftProbe;
use App\Servers\FakeRemoteShell;
use App\Servers\MinecraftProbe;
use App\Servers\RemoteShell;
use App\Servers\ServerLauncher;
use App\Servers\WorldSync;
use App\WorldStorage\Archives;
use App\WorldStorage\WorldStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * World storage part 2 (#5288): the VPS downloads the current world before Minecraft
 * starts, and the stop uploads a new archive before the VPS is deleted.
 */
class WorldSyncTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private WorldStorageSetting $storageRow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRow = $this->useFakeWorldStorage();
        Notification::fake();
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

    private function probe(): FakeMinecraftProbe
    {
        $probe = $this->app->make(MinecraftProbe::class);
        $this->assertInstanceOf(FakeMinecraftProbe::class, $probe);

        return $probe;
    }

    private function launcher(): ServerLauncher
    {
        return $this->app->make(ServerLauncher::class);
    }

    private function server(): Server
    {
        return Server::query()->where('slug', 'vanilla')->first()
            ?? Server::factory()->create(['name' => 'Vanilla', 'slug' => 'vanilla']);
    }

    /**
     * A world on (fake) storage that is the server's current archive.
     */
    private function currentArchive(string $bytes = 'a saved world'): WorldArchive
    {
        $server = $this->server();
        $archives = $this->app->make(Archives::class);
        $path = $archives->newPath($server, 'upload.zip');
        $this->app->make(WorldStorage::class)->disk()->put($path, $bytes);

        return $archives->record($server, $path, strlen($bytes), hash('sha256', $bytes), 'upload');
    }

    private function startAndBoot(): ServerRun
    {
        $run = $this->launcher()->start($this->server(), User::factory()->create(), null)->refresh();
        $this->cloud()->setStatus((int) $run->provider_server_id, 'running');
        $this->artisan('servers:watch')->assertSuccessful();

        return $run->refresh();
    }

    /**
     * @return array<string, array{host: string, path: string, contents: string, mode: int}>
     */
    private function filesByPath(): array
    {
        return collect($this->shell()->files())->keyBy('path')->all();
    }

    public function test_a_start_is_refused_until_world_storage_is_set_up(): void
    {
        $this->storageRow->delete();
        $player = User::factory()->create();
        $this->server();

        $this->actingAs($player)->post('/servers/vanilla/start')
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', "Servers can't start until world storage is set up (an admin does that in Admin → World storage), because the world couldn't be saved when it stops.");

        $this->assertSame(0, ServerRun::query()->count());
        $this->assertSame([], $this->cloud()->managedServers());
    }

    public function test_the_vps_gets_the_current_world_before_minecraft_starts(): void
    {
        $archive = $this->currentArchive();

        $run = $this->startAndBoot();

        $this->assertSame(RunStatus::Online, $run->status);
        $this->assertNotNull($run->prepared_at);
        $this->assertSame($archive->id, $run->world_archive_id);
        $this->assertSame([WorldSync::PREPARE_COMMAND], array_column($this->shell()->commands(), 'command'));
        $this->assertSame([$run->ssh_private_key], $this->shell()->keys(), 'Logged in with the run\'s own key.');

        $files = $this->filesByPath();
        // Plus the log shipper's files (#5367).
        $this->assertSame(['/root/.mvm/prepare.sh', '/root/.mvm/known_hosts', '/root/.mvm/password', '/root/.mvm/askpass', '/root/.mvm-log/ship.sh', '/root/.mvm-log/curl.conf', '/root/.mvm-log/mvm-log-ship.service'], array_keys($files));
        $this->assertSame(0700, $files['/root/.mvm/prepare.sh']['mode']);
        $this->assertSame(0600, $files['/root/.mvm/password']['mode']);
        $this->assertSame('hunter2-secret', $files['/root/.mvm/password']['contents']);
        $this->assertStringStartsWith('storage.example.com ssh-ed25519 ', $files['/root/.mvm/known_hosts']['contents']);

        $script = $files['/root/.mvm/prepare.sh']['contents'];
        $this->assertStringContainsString("P='{$archive->path}'", $script);
        $this->assertStringContainsString("[ \"\$size\" != '{$archive->size}' ]", $script);
        $this->assertStringContainsString($archive->sha256, $script);
        $this->assertStringContainsString('-o StrictHostKeyChecking=yes', $script);
        $this->assertStringContainsString("TARGET='minecraft@storage.example.com'", $script);
        $this->assertStringContainsString('systemctl start "$UNIT"', $script);

        // The password is only ever in its own file: never in a script or a command line.
        foreach ($this->shell()->commands() as $command) {
            $this->assertStringNotContainsString('hunter2-secret', $command['command']);
        }
        $this->assertStringNotContainsString('hunter2-secret', $script);
    }

    public function test_a_server_without_a_saved_world_starts_without_the_storage_login(): void
    {
        $run = $this->startAndBoot();

        $this->assertSame(RunStatus::Online, $run->status);
        $this->assertNull($run->world_archive_id);
        $this->assertSame(['/root/.mvm/prepare.sh', '/root/.mvm-log/ship.sh', '/root/.mvm-log/curl.conf', '/root/.mvm-log/mvm-log-ship.service'], array_keys($this->filesByPath()));
        $this->assertStringNotContainsString('fetch "$P"', $this->filesByPath()['/root/.mvm/prepare.sh']['contents']);
    }

    public function test_minecraft_isnt_probed_before_the_world_is_in_place(): void
    {
        $this->shell()->unreachable('Connection refused');

        $run = $this->startAndBoot();

        // sshd isn't up yet: still starting, and queued again for the next check.
        $this->assertSame(RunStatus::Starting, $run->status);
        $this->assertNull($run->prepared_at);
        $this->assertNull($run->prepare_queued_at);
        $this->assertNull($run->online_at, 'The fake probe would say yes, but nothing was started yet.');

        $this->shell()->succeed();
        $this->artisan('servers:watch')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Online, $run->status);
        $this->assertNotNull($run->prepared_at);
    }

    public function test_another_setup_still_running_is_waited_for(): void
    {
        $this->shell()->failMatching(WorldSync::PREPARE_COMMAND, WorldSync::EXIT_BUSY, 'Another world setup or save is still running on this VPS.');

        $run = $this->startAndBoot();

        $this->assertSame(RunStatus::Starting, $run->status);
        $this->assertNull($run->prepare_queued_at);
    }

    public function test_a_world_that_fails_its_check_fails_the_start_and_deletes_the_vps(): void
    {
        $this->currentArchive();
        $this->shell()->failMatching(WorldSync::PREPARE_COMMAND, 22, "The downloaded world doesn't match the saved one (SHA-256 differs).");

        $run = $this->startAndBoot();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertNull($run->active_server_id);
        $this->assertStringContainsString('Setting up the server failed (exit 22', (string) $run->error);
        $this->assertStringContainsString('SHA-256 differs', (string) $run->error);
        $this->assertSame([], $this->cloud()->managedServers());
        $this->assertSame([], $this->cloud()->managedSshKeys());
    }

    public function test_the_stop_uploads_the_world_and_makes_it_current_before_the_vps_goes(): void
    {
        $first = $this->currentArchive();
        $run = $this->startAndBoot();
        $vpsId = (int) $run->provider_server_id;
        $this->shell()->forget();

        $this->assertTrue($this->launcher()->requestStop($run, 'admin'));

        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status);
        $this->assertNull($this->cloud()->findServer($vpsId));

        $commands = array_column($this->shell()->commands(), 'command');
        $this->assertCount(2, $commands);
        $this->assertStringStartsWith('systemctl stop minecraft-vanilla.service;', $commands[0]);
        $this->assertMatchesRegularExpression("#^sh /root/\\.mvm/save\\.sh 'vanilla/\\d{8}-\\d{6}-[a-z0-9]{6}-save\\.zip'$#", $commands[1]);

        $saved = WorldArchive::query()->findOrFail($run->saved_world_archive_id);
        $this->assertSame('save', $saved->source);
        $this->assertSame($run->id, $saved->server_run_id);
        $this->assertNull($saved->uploaded_by);
        $this->assertSame($saved->id, $this->server()->refresh()->current_world_archive_id);
        $this->assertNotSame($first->id, $saved->id);

        $bytes = $this->app->make(WorldStorage::class)->disk()->get($saved->path);
        $this->assertSame(strlen((string) $bytes), $saved->size);
        $this->assertSame(hash('sha256', (string) $bytes), $saved->sha256);

        // The save script got the storage login and the host key, the password in its own file.
        $files = $this->filesByPath();
        $this->assertArrayHasKey('/root/.mvm/save.sh', $files);
        $this->assertSame('hunter2-secret', $files['/root/.mvm/password']['contents']);
        $this->assertStringNotContainsString('hunter2-secret', $files['/root/.mvm/save.sh']['contents']);

        // The next start downloads what this stop saved.
        $next = $this->startAndBoot();
        $this->assertSame($saved->id, $next->world_archive_id);
    }

    public function test_only_the_newest_three_archives_are_kept(): void
    {
        $this->currentArchive();

        foreach (range(1, 4) as $i) {
            $run = $this->startAndBoot();
            $this->launcher()->requestStop($run, 'admin');
            $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
            $this->travel(1)->minutes();
        }

        $this->assertSame(3, WorldArchive::query()->count());
        $this->assertSame(['save', 'save', 'save'], WorldArchive::query()->pluck('source')->all());
    }

    public function test_a_failed_upload_keeps_the_vps_and_the_retry_saves_it(): void
    {
        config(['minecraft.alert_mail' => 'ops@example.com']);
        $run = $this->startAndBoot();
        $vpsId = (int) $run->provider_server_id;
        $this->shell()->failMatching(WorldSync::SAVE_COMMAND, 31, "The uploaded world didn't read back the same (SHA-256 differs): nothing was kept.");

        $this->launcher()->requestStop($run, 'idle');

        $run->refresh();
        $this->assertSame(RunStatus::StopFailed, $run->status);
        $this->assertSame($vpsId, $run->provider_server_id);
        $this->assertNotNull($this->cloud()->findServer($vpsId), 'The VPS (and the world on it) is kept.');
        $this->assertStringContainsString('Minecraft stopped, but uploading the world failed (exit 31', (string) $run->error);
        $this->assertNull($run->saved_world_archive_id);
        $this->assertSame(0, WorldArchive::query()->count());
        Notification::assertSentOnDemandTimes(ServerAlert::class, 1);

        // The sweep tries again once storage works.
        $this->shell()->succeed();
        $this->artisan('servers:sweep')->assertSuccessful();

        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status);
        $this->assertNotNull($run->saved_world_archive_id);
        $this->assertNull($this->cloud()->findServer($vpsId));
    }

    public function test_an_upload_the_app_cant_find_on_storage_doesnt_count(): void
    {
        $run = $this->startAndBoot();
        // save.sh says it worked, but nothing is at the path on storage.
        $this->shell()->failMatching(WorldSync::SAVE_COMMAND, 0, 'size=1234 sha256='.str_repeat('a', 64)."\n");

        $this->launcher()->requestStop($run, 'admin');

        $run->refresh();
        $this->assertSame(RunStatus::StopFailed, $run->status);
        $this->assertStringContainsString("The world was uploaded, but the app couldn't check it on world storage", (string) $run->error);
        $this->assertSame(0, WorldArchive::query()->count());
    }

    public function test_an_empty_server_folder_saves_nothing_and_still_stops(): void
    {
        $run = $this->startAndBoot();
        $this->shell()->emptyWorld();

        $this->launcher()->requestStop($run, 'admin');

        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status);
        $this->assertNull($run->saved_world_archive_id);
        $this->assertSame(0, WorldArchive::query()->count());
    }

    public function test_storage_that_stopped_working_keeps_the_vps(): void
    {
        $run = $this->startAndBoot();
        $this->storageRow->host_key = null;
        $this->storageRow->save();

        $this->launcher()->requestStop($run, 'admin');

        $run->refresh();
        $this->assertSame(RunStatus::StopFailed, $run->status);
        $this->assertStringContainsString("Minecraft stopped, but the world couldn't be saved", (string) $run->error);
        $this->assertNotNull($this->cloud()->findServer((int) $run->provider_server_id));
    }

    public function test_a_retried_stop_after_a_failed_delete_doesnt_save_twice(): void
    {
        $run = $this->startAndBoot();
        // Hetzner refuses the delete: the VPS lost its managed-by label.
        $this->cloud()->reset();
        $unmanaged = $this->cloud()->addUnmanagedServer('mvm-vanilla-'.$run->id);
        $run->provider_server_id = $unmanaged->id;
        $run->save();
        $this->shell()->forget();

        $this->launcher()->requestStop($run, 'admin');
        $this->assertSame(RunStatus::StopFailed, $run->refresh()->status);
        $this->assertSame(1, WorldArchive::query()->count());

        $this->launcher()->requestStop($run->refresh(), 'admin');

        $this->assertSame(RunStatus::StopFailed, $run->refresh()->status);
        $this->assertSame(1, WorldArchive::query()->count(), 'Saved once.');
        $saves = array_filter(array_column($this->shell()->commands(), 'command'), fn (string $c) => str_starts_with($c, WorldSync::SAVE_COMMAND));
        $this->assertCount(1, $saves);
    }

    public function test_a_server_stopped_before_it_was_set_up_saves_nothing(): void
    {
        $this->shell()->unreachable();
        $run = $this->startAndBoot();
        $this->assertSame(RunStatus::Starting, $run->status);
        $this->shell()->succeed();
        $this->shell()->forget();

        $this->launcher()->requestStop($run, 'admin');

        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
        $this->assertSame([], $this->shell()->commands(), 'Minecraft never ran: nothing to stop or save.');
    }

    public function test_the_probe_still_decides_online_after_setup(): void
    {
        $this->probe()->refuse();

        $run = $this->startAndBoot();

        $this->assertSame(RunStatus::Starting, $run->status);
        $this->assertNotNull($run->prepared_at);
        $this->assertCount(1, $this->shell()->commands());

        // Later checks don't set it up again.
        $this->artisan('servers:watch')->assertSuccessful();
        $this->assertCount(1, $this->shell()->commands());
    }
}
