<?php

namespace App\WorldStorage;

use App\Models\WorldStorageSetting;
use Aws\Exception\AwsException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use phpseclib3\Crypt\PublicKeyLoader;
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

    // A password the fake SFTP server refuses, so tests and E2E can see a refused sign-in.
    public const FAKE_WRONG_PASSWORD = 'wrong-password';

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

            // Getting the host key needs no sign-in, so sign in on its own before writing
            // anything: a refused login is then reported as that (#5348).
            $refused = $this->signIn($settings);

            if ($refused !== null) {
                return ConnectionTest::failed($refused, $hostKey);
            }
        }

        if ($this->fake && $settings->host === self::FAKE_UNREACHABLE_HOST) {
            return ConnectionTest::failed("Couldn't connect to {$settings->host}:{$settings->port}: Connection timed out");
        }

        if ($this->fake && $settings->isS3() && $settings->password === self::FAKE_WRONG_PASSWORD) {
            return ConnectionTest::failed($this->s3Prefix($settings).'The request signature we calculated does not match the signature you provided. Check your key and signing method. (SignatureDoesNotMatch)');
        }

        try {
            $disk = $this->disk($settings);
            $disk->put(self::PROBE_FILE, 'ok');
            $size = $disk->size(self::PROBE_FILE);
            $disk->delete(self::PROBE_FILE);
            $disk->deleteDirectory(self::PROBE_DIR);
        } catch (Throwable $e) {
            // SFTP has signed in by now; FTP signs in here, so its message can't say which step failed.
            $prefix = match ($settings->driver) {
                'sftp' => "Signed in, but couldn't write to the base folder \"{$settings->root}\": ",
                's3' => $this->s3Prefix($settings),
                default => "Couldn't sign in or write to the base folder \"{$settings->root}\" on {$settings->host}:{$settings->port}: ",
            };

            return ConnectionTest::failed($prefix.$this->describe($e), $hostKey);
        }

        if ($size !== 2) {
            return ConnectionTest::failed("Connected, but a test file came back with the wrong size ({$size} bytes).", $hostKey);
        }

        return ConnectionTest::passed($hostKey);
    }

    /**
     * Sign in the way Flysystem will (key first, then the password), and disconnect.
     *
     * @return string|null why it was refused (never with a secret in it), or null when it worked
     */
    private function signIn(StorageSettings $settings): ?string
    {
        if ($this->fake) {
            return $settings->password === self::FAKE_WRONG_PASSWORD && $settings->privateKey === null
                ? $this->refusedMessage($settings, ['publickey', 'password'])
                : null;
        }

        try {
            $sftp = new SFTP($settings->host, $settings->port, 15);
            $signedIn = false;

            if ($settings->privateKey !== null) {
                $signedIn = $sftp->login($settings->username, PublicKeyLoader::load($settings->privateKey));
            }

            if (! $signedIn && $settings->password !== null) {
                $signedIn = $sftp->login($settings->username, $settings->password);
            }

            $methods = $sftp->getAuthMethodsToContinue();
            $sftp->disconnect();
        } catch (Throwable $e) {
            return "Couldn't sign in to {$settings->host}:{$settings->port}: ".$this->describe($e);
        }

        if ($signedIn) {
            return null;
        }

        return $this->refusedMessage($settings, is_array($methods) ? array_values(array_filter($methods, 'is_string')) : []);
    }

    /**
     * @param  list<string>  $methods  what the server said it accepts
     */
    private function refusedMessage(StorageSettings $settings, array $methods): string
    {
        $with = match (true) {
            $settings->privateKey !== null && $settings->password !== null => 'that private key or password',
            $settings->privateKey !== null => 'that private key',
            default => 'that password',
        };

        $message = "Reached {$settings->host}:{$settings->port}, but it refused to sign in user \"{$settings->username}\" with {$with}.";

        if ($methods !== []) {
            $message .= ' It accepts: '.implode(', ', $methods).'.';
        }

        $message .= ' Check the user and the password or key.';

        if (str_ends_with(strtolower($settings->host), '.your-storagebox.de')) {
            $message .= ' On a Hetzner Storage Box the user is u123456 (u123456-sub1 for a sub-account), the password is the'
                .' one set in the Hetzner Console (you can reset it there), and "External reachability" must be on'
                .' unless this app runs inside Hetzner\'s network. SFTP is on port 22.';
        }

        return $message;
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

    private function s3Prefix(StorageSettings $settings): string
    {
        $where = $settings->root !== '' ? " under \"{$settings->root}/\"" : '';

        return "Couldn't write to bucket \"{$settings->bucket}\"{$where} on {$settings->host}: ";
    }

    private function describe(Throwable $e): string
    {
        if ($e instanceof AwsException) {
            // The SDK's own message holds the whole request URL; the error code and S3's
            // sentence are what helps.
            $code = $e->getAwsErrorCode();
            $text = $e->getAwsErrorMessage();

            if ($code !== null || $text !== null) {
                return mb_substr(trim(($text ?? 'Request failed').($code !== null ? " ({$code})" : '')), 0, 300);
            }
        }

        $previous = $e->getPrevious();

        if ($previous instanceof AwsException) {
            return $this->describe($previous);
        }

        $message = trim($e->getMessage());
        $previous = $e->getPrevious();

        if ($previous !== null && trim($previous->getMessage()) !== '') {
            $message .= ' ('.trim($previous->getMessage()).')';
        }

        return mb_substr($message !== '' ? $message : class_basename($e), 0, 300);
    }
}
