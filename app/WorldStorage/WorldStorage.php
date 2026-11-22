<?php

namespace App\WorldStorage;

use App\Models\WorldStorageSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * The world storage server (#5288): which settings apply, a Flysystem disk for it, and the
 * connection test. In the testing and e2e environments (or with WORLD_STORAGE_FAKE) the disk
 * is a local folder and the test never opens a connection: the settings still come from the
 * database, so the admin page behaves the same.
 */
class WorldStorage
{
    // In a folder of its own: Flysystem makes missing parent folders, so a new base folder
    // is created too (a file straight in a missing base folder fails).
    private const PROBE_DIR = '.mvm-connection-test';

    private const PROBE_FILE = self::PROBE_DIR.'/probe';

    // What the fake hands back as the SFTP server's host key.
    public const FAKE_HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGZha2Utd29ybGQtc3RvcmFnZS1ob3N0LWtleQ==';

    // A host the fake treats as unreachable, so tests and E2E can see a failed test.
    public const FAKE_UNREACHABLE_HOST = 'unreachable.invalid';

    public function __construct(
        private readonly bool $fake,
        private readonly string $fakeRoot,
    ) {}

    public function isFake(): bool
    {
        return $this->fake;
    }

    public function saved(): ?WorldStorageSetting
    {
        return WorldStorageSetting::query()->first();
    }

    /**
     * Where the settings come from: "database" (Admin → World storage), "env", or null.
     */
    public function source(): ?string
    {
        if ($this->saved() !== null) {
            return 'database';
        }

        /** @var array<string, mixed> $env */
        $env = config('world_storage.env');

        return StorageSettings::fromEnv($env) !== null ? 'env' : null;
    }

    /**
     * The settings starts and stops use.
     *
     * @throws StorageUnavailable when nothing is set up, it isn't complete, or the secrets can't be read
     */
    public function settings(): StorageSettings
    {
        $row = $this->saved();

        if ($row !== null) {
            try {
                $settings = StorageSettings::fromModel($row);
            } catch (DecryptException) {
                throw StorageUnavailable::secretsUnreadable();
            }
        } else {
            /** @var array<string, mixed> $env */
            $env = config('world_storage.env');
            $settings = StorageSettings::fromEnv($env) ?? throw StorageUnavailable::notSetUp();
        }

        if (! $settings->isComplete()) {
            throw StorageUnavailable::incomplete($settings);
        }

        return $settings;
    }

    /**
     * @throws StorageUnavailable
     */
    public function disk(?StorageSettings $settings = null): Filesystem
    {
        $settings ??= $this->settings();

        if ($this->fake) {
            return Storage::build(['driver' => 'local', 'root' => $this->fakeRoot, 'throw' => true]);
        }

        return Storage::build($settings->diskConfig());
    }

    /**
     * Connect, check the host key (SFTP), then write, size and delete a small file in the
     * base folder. Never puts a secret in the message.
     *
     * @param  string|null  $pinnedHostKey  the key saved last time; another key fails unless $trustNewHostKey
     */
    public function test(StorageSettings $settings, ?string $pinnedHostKey, bool $trustNewHostKey): ConnectionTest
    {
        $hostKey = null;

        if ($settings->driver === 'sftp') {
            try {
                $hostKey = $this->fetchHostKey($settings);
            } catch (Throwable $e) {
                return ConnectionTest::failed("Couldn't connect to {$settings->host}:{$settings->port}: ".$this->describe($e));
            }

            if ($pinnedHostKey !== null && ! $trustNewHostKey
                && StorageSettings::hostKeyBlob($pinnedHostKey) !== StorageSettings::hostKeyBlob($hostKey)) {
                return ConnectionTest::failed(
                    "The server's host key has changed (now ".StorageSettings::fingerprint($hostKey)
                    .', saved '.StorageSettings::fingerprint($pinnedHostKey).'). If you changed the storage server, '
                    .'tick "Trust the new host key" and test again. Otherwise someone may be in the way: don\'t save.',
                    $hostKey,
                );
            }

            $settings = $settings->withHostKey($hostKey);
        }

        if ($this->fake && $settings->host === self::FAKE_UNREACHABLE_HOST) {
            return ConnectionTest::failed("Couldn't connect to {$settings->host}:{$settings->port}: Connection timed out");
        }

        try {
            $disk = $this->disk($settings);
            $disk->put(self::PROBE_FILE, 'ok');
            $size = $disk->size(self::PROBE_FILE);
            $disk->delete(self::PROBE_FILE);
            $disk->deleteDirectory(self::PROBE_DIR);
        } catch (Throwable $e) {
            return ConnectionTest::failed("Connected, but couldn't write to the base folder \"{$settings->root}\": ".$this->describe($e), $hostKey);
        }

        if ($size !== 2) {
            return ConnectionTest::failed("Connected, but a test file came back with the wrong size ({$size} bytes).", $hostKey);
        }

        return ConnectionTest::passed($hostKey);
    }

    private function fetchHostKey(StorageSettings $settings): string
    {
        if ($this->fake) {
            if ($settings->host === self::FAKE_UNREACHABLE_HOST) {
                throw new StorageUnavailable('Connection timed out');
            }

            return self::FAKE_HOST_KEY;
        }

        $sftp = new SFTP($settings->host, $settings->port, 15);
        $key = $sftp->getServerPublicHostKey();
        $sftp->disconnect();

        if (! is_string($key) || StorageSettings::hostKeyBlob($key) === null) {
            throw new StorageUnavailable('the server sent no SSH host key (is it an SFTP server?)');
        }

        return $key;
    }

    private function describe(Throwable $e): string
    {
        $message = trim($e->getMessage());
        $previous = $e->getPrevious();

        if ($previous !== null && trim($previous->getMessage()) !== '') {
            $message .= ' ('.trim($previous->getMessage()).')';
        }

        return mb_substr($message !== '' ? $message : class_basename($e), 0, 300);
    }
}
