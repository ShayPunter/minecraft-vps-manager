<?php

namespace App\WorldStorage;

use App\Models\WorldStorageSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use InvalidArgumentException;

/**
 * How to reach the world storage server: from Admin → World storage, or the env fallback.
 */
final readonly class StorageSettings
{
    public const DRIVERS = ['sftp', 'ftp'];

    // Checked on save, and again wherever these end up in a shell script on a VPS.
    public const HOST_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/';

    public const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public const ROOT_PATTERN = '/^\/?[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/';

    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $username,
        public ?string $password,
        public ?string $privateKey,
        public string $root,
        public bool $ftps,
        public ?string $hostKey,
    ) {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException("Unknown world storage driver [{$driver}].");
        }
    }

    public static function defaultPort(string $driver): int
    {
        return $driver === 'ftp' ? 21 : 22;
    }

    /**
     * @throws DecryptException when APP_KEY changed since the secrets were saved
     */
    public static function fromModel(WorldStorageSetting $row): self
    {
        return new self(
            driver: $row->driver,
            host: $row->host,
            port: $row->port,
            username: $row->username,
            password: $row->password,
            privateKey: $row->private_key,
            root: $row->root,
            ftps: $row->ftps,
            hostKey: $row->host_key,
        );
    }

    /**
     * @param  array<string, mixed>  $env  config('world_storage.env')
     */
    public static function fromEnv(array $env): ?self
    {
        if (! is_string($env['host'] ?? null) || ! is_string($env['username'] ?? null)) {
            return null;
        }

        $driver = in_array($env['driver'] ?? null, self::DRIVERS, true) ? $env['driver'] : 'sftp';
        $keyPath = $env['private_key_path'] ?? null;
        $privateKey = is_string($keyPath) && is_readable($keyPath) ? (string) file_get_contents($keyPath) : null;

        return new self(
            driver: $driver,
            host: $env['host'],
            port: is_int($env['port'] ?? null) ? $env['port'] : self::defaultPort($driver),
            username: $env['username'],
            password: is_string($env['password'] ?? null) ? $env['password'] : null,
            privateKey: $privateKey,
            root: is_string($env['root'] ?? null) ? $env['root'] : 'minecraft-worlds',
            ftps: (bool) ($env['ftps'] ?? true),
            hostKey: is_string($env['host_key'] ?? null) ? $env['host_key'] : null,
        );
    }

    public function withHostKey(?string $hostKey): self
    {
        return new self($this->driver, $this->host, $this->port, $this->username, $this->password,
            $this->privateKey, $this->root, $this->ftps, $hostKey);
    }

    /**
     * Usable for starts and stops: SFTP needs a pinned host key, FTP a password.
     */
    public function isComplete(): bool
    {
        return $this->driver === 'sftp'
            ? $this->hostKey !== null && ($this->password !== null || $this->privateKey !== null)
            : $this->password !== null;
    }

    /**
     * "SHA256:…" as OpenSSH prints it, for the admin page.
     */
    public function hostKeyFingerprint(): ?string
    {
        return self::fingerprint($this->hostKey);
    }

    public static function fingerprint(?string $hostKey): ?string
    {
        $blob = self::hostKeyBlob($hostKey);

        return $blob === null ? null : 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    public static function hostKeyBlob(?string $hostKey): ?string
    {
        if ($hostKey === null) {
            return null;
        }

        $parts = explode(' ', trim($hostKey));
        $blob = isset($parts[1]) ? base64_decode($parts[1], true) : false;

        return $blob === false || $blob === '' ? null : $blob;
    }

    /**
     * A Laravel filesystem disk config (league/flysystem-sftp-v3 or -ftp).
     *
     * @return array<string, mixed>
     */
    public function diskConfig(): array
    {
        if ($this->driver === 'ftp') {
            return [
                'driver' => 'ftp',
                'host' => $this->host,
                'port' => $this->port,
                'username' => $this->username,
                'password' => $this->password,
                'root' => $this->root,
                'ssl' => $this->ftps,
                'passive' => true,
                'timeout' => 20,
                'throw' => true,
            ];
        }

        $fingerprint = $this->hostKeyFingerprint();

        return array_filter([
            'driver' => 'sftp',
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'privateKey' => $this->privateKey,
            'root' => $this->root,
            // Flysystem refuses a server that answers with another key.
            'hostFingerprint' => $fingerprint !== null ? 'sha256:'.substr($fingerprint, 7) : null,
            'timeout' => 20,
            'throw' => true,
        ], fn ($value) => $value !== null);
    }
}
