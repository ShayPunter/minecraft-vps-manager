<?php

namespace Tests\Concerns;

use App\Models\WorldStorageSetting;
use App\WorldStorage\WorldStorage;
use Illuminate\Support\Facades\File;

/**
 * Starts need world storage (#5288): a saved SFTP setting and an empty local folder standing
 * in for the storage server. Call useFakeWorldStorage() in setUp, before anything resolves
 * ServerLauncher (it holds the WorldStorage instance).
 */
trait UsesFakeWorldStorage
{
    protected string $worldStorageRoot;

    protected function useFakeWorldStorage(): WorldStorageSetting
    {
        $this->worldStorageRoot = sys_get_temp_dir().'/mvm-world-storage-test-'.bin2hex(random_bytes(6));
        config(['world_storage.fake_root' => $this->worldStorageRoot]);
        $this->app->forgetInstance(WorldStorage::class);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->worldStorageRoot));

        $row = new WorldStorageSetting;
        $row->driver = 'sftp';
        $row->host = 'storage.example.com';
        $row->port = 22;
        $row->username = 'minecraft';
        $row->password = 'hunter2-secret';
        $row->root = 'minecraft-worlds';
        $row->ftps = true;
        $row->host_key = WorldStorage::FAKE_HOST_KEY;
        $row->save();

        return $row;
    }
}
