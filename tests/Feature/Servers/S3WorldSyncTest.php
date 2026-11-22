<?php

namespace Tests\Feature\Servers;

use App\Cloud\CloudProvider;
use App\Cloud\FakeCloudProvider;
use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\WorldArchive;
use App\Models\WorldStorageSetting;
use App\Servers\FakeRemoteShell;
use App\Servers\RemoteShell;
use App\Servers\ServerLauncher;
use App\Servers\WorldSync;
use App\WorldStorage\Archives;
use App\WorldStorage\S3Transfers;
use App\WorldStorage\WorldStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\UsesFakeWorldStorage;
use Tests\TestCase;

/**
 * Starts and stops with S3 world storage (#5347): the VPS gets pre-signed links only, never
 * the access or secret key; the app starts the multipart upload and aborts it when a save
 * doesn't count.
 */
class S3WorldSyncTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeWorldStorage;

    private const SECRET = 's3-secret-never-on-a-vps';

    private const ACCESS_KEY = 'AKIAACCESSKEYEXAMPLE';

    protected function setUp(): void
    {
        parent::setUp();

        $row = $this->useFakeWorldStorage();
        $row->driver = 's3';
        $row->host = 'fsn1.your-objectstorage.com';
        $row->port = 443;
        $row->username = self::ACCESS_KEY;
        $row->password = self::SECRET;
        $row->host_key = null;
        $row->endpoint = 'https://fsn1.your-objectstorage.com';
        $row->region = 'fsn1';
        $row->bucket = 'mvm-worlds';
        $row->root = 'worlds';
        $row->save();

        Notification::fake();
    }

    private function shell(): FakeRemoteShell
    {
        $shell = $this->app->make(RemoteShell::class);
        $this->assertInstanceOf(FakeRemoteShell::class, $shell);

        return $shell;
    }

    private function s3(): S3Transfers
    {
        return $this->app->make(S3Transfers::class);
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

    private function startAndBoot(): ServerRun
    {
        $run = $this->launcher()->start($this->server(), null, null)->refresh();
        $cloud = $this->app->make(CloudProvider::class);
        $this->assertInstanceOf(FakeCloudProvider::class, $cloud);
        $cloud->setStatus((int) $run->provider_server_id, 'running');
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

    private function assertNoKeysOnTheVps(): void
    {
        foreach ($this->shell()->files() as $file) {
            $this->assertStringNotContainsString(self::SECRET, $file['contents'], $file['path']);
            $this->assertStringNotContainsString(self::ACCESS_KEY, $file['contents'], $file['path']);
        }

        foreach ($this->shell()->commands() as $command) {
            $this->assertStringNotContainsString(self::SECRET, $command['command']);
            $this->assertStringNotContainsString('X-Amz-Signature', $command['command'], 'Links stay out of command lines.');
        }
    }

    public function test_a_start_downloads_with_a_presigned_link_and_no_keys(): void
    {
        $server = $this->server();
        $archives = $this->app->make(Archives::class);
        $path = $archives->newPath($server, 'upload.zip');
        $this->app->make(WorldStorage::class)->disk()->put($path, 'a saved world');
        $archive = $archives->record($server, $path, 13, hash('sha256', 'a saved world'), 'upload');

        $run = $this->startAndBoot();

        $this->assertSame(RunStatus::Online, $run->status);
        $this->assertSame($archive->id, $run->world_archive_id);

        $files = $this->filesByPath();
        $this->assertSame(['/root/.mvm/prepare.sh', '/root/.mvm/get.conf'], array_keys($files));
        $this->assertSame(0600, $files['/root/.mvm/get.conf']['mode']);
        $this->assertSame('url = "https://fake-s3.invalid/mvm-worlds/worlds/'.$path.'?mvm=get&X-Amz-Signature=fake"'."\n", $files['/root/.mvm/get.conf']['contents']);
        $this->assertStringContainsString('fetch() { cu --config "$M/get.conf" -o "$2"; }', $files['/root/.mvm/prepare.sh']['contents']);
        $this->assertNoKeysOnTheVps();
    }

    public function test_a_stop_uploads_in_parts_and_records_the_archive(): void
    {
        config(['world_storage.s3_part_mb' => 64, 'world_storage.s3_max_world_gb' => 2]);
        $run = $this->startAndBoot();
        $this->shell()->forget();

        $this->assertTrue($this->launcher()->requestStop($run, 'admin'));

        $run->refresh();
        $this->assertSame(RunStatus::Stopped, $run->status);
        $saved = WorldArchive::query()->findOrFail($run->saved_world_archive_id);
        $this->assertSame($saved->id, $this->server()->refresh()->current_world_archive_id);

        $files = $this->filesByPath();
        $this->assertSame(['/root/.mvm/save.sh', '/root/.mvm/parts', '/root/.mvm/complete.conf', '/root/.mvm/get.conf'], array_keys($files));

        // 2 GB in 64 MB parts = 32 links, one curl config line each, in order.
        $parts = explode("\n", rtrim($files['/root/.mvm/parts']['contents']));
        $this->assertCount(32, $parts);
        $this->assertStringContainsString('?mvm=part1&', $parts[0]);
        $this->assertStringContainsString('?mvm=part32&', $parts[31]);
        $this->assertStringContainsString('worlds/'.$saved->path.'?mvm=complete', $files['/root/.mvm/complete.conf']['contents']);
        $this->assertStringContainsString("PART_MB=64\nMAX_PARTS=32\n", $files['/root/.mvm/save.sh']['contents']);

        $command = array_column($this->shell()->commands(), 'command')[1];
        $this->assertSame(WorldSync::SAVE_COMMAND." '{$saved->path}'", $command);
        $this->assertSame([['action' => 'started', 'path' => $saved->path]], array_map(
            fn (array $entry) => ['action' => $entry['action'], 'path' => $entry['path']], $this->s3()->fakeLog()));
        $this->assertNoKeysOnTheVps();
    }

    public function test_a_failed_save_aborts_the_upload_and_keeps_the_vps(): void
    {
        $run = $this->startAndBoot();
        $this->shell()->failMatching(WorldSync::SAVE_COMMAND, 34, "World storage didn't finish the upload: <Error><Code>InvalidPart</Code></Error>");

        $this->launcher()->requestStop($run, 'idle');

        $run->refresh();
        $this->assertSame(RunStatus::StopFailed, $run->status);
        $this->assertStringContainsString('(exit 34', (string) $run->error);
        $this->assertSame(['started', 'aborted'], array_column($this->s3()->fakeLog(), 'action'));
        $log = $this->s3()->fakeLog();
        $this->assertSame($log[0]['upload_id'], $log[1]['upload_id']);

        // The retry starts a new upload and saves.
        $this->shell()->succeed();
        $this->artisan('servers:sweep')->assertSuccessful();
        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
        $this->assertSame(['started', 'aborted', 'started'], array_column($this->s3()->fakeLog(), 'action'));
    }

    public function test_an_empty_world_aborts_the_unused_upload(): void
    {
        $run = $this->startAndBoot();
        $this->shell()->emptyWorld();

        $this->launcher()->requestStop($run, 'idle');

        $this->assertSame(RunStatus::Stopped, $run->refresh()->status);
        $this->assertNull($run->saved_world_archive_id);
        $this->assertSame(['started', 'aborted'], array_column($this->s3()->fakeLog(), 'action'));
    }

    public function test_storage_that_became_unusable_fails_the_stop_safely(): void
    {
        $run = $this->startAndBoot();
        // The secret key is gone, so nothing can be signed.
        WorldStorageSetting::query()->update(['password' => null]);

        $this->launcher()->requestStop($run, 'idle');

        $this->assertSame(RunStatus::StopFailed, $run->refresh()->status);
        $this->assertNotNull($this->app->make(CloudProvider::class)->findServer((int) $run->provider_server_id));
    }
}
