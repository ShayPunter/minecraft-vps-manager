<?php

namespace Tests\Feature\WorldStorage;

use App\Models\WorldStorageSetting;
use App\WorldStorage\WorldStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every test gets its own empty local folder standing in for the storage server.
 */
abstract class WorldStorageTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/mvm-world-storage-test-'.bin2hex(random_bytes(6));
        config(['world_storage.fake_root' => $this->storageRoot]);
        $this->app->forgetInstance(WorldStorage::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);

        parent::tearDown();
    }

    protected function saveSettings(array $overrides = []): WorldStorageSetting
    {
        $row = new WorldStorageSetting;
        $row->driver = 'sftp';
        $row->host = 'storage.example.com';
        $row->port = 22;
        $row->username = 'minecraft';
        $row->password = 'hunter2-secret';
        $row->root = 'minecraft-worlds';
        $row->ftps = true;
        $row->host_key = WorldStorage::FAKE_HOST_KEY;

        foreach ($overrides as $key => $value) {
            $row->{$key} = $value;
        }

        $row->save();

        return $row;
    }

    /**
     * A zip on disk with the given entries (name => contents).
     *
     * @param  array<string, string>  $entries
     */
    protected function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mvm-zip').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }
}
