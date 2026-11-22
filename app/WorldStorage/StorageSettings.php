<?php

namespace App\WorldStorage;

use App\Models\WorldStorageSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use InvalidArgumentException;

/**
 * How to reach the world storage server: from Admin → World storage, or the env fallback.
 *
 * S3-compatible storage (#5347) reuses the login fields: `username` is the access key and
 * `password` the secret key. `host`/`port` are the endpoint's (for messages and logs only),
 * and `root` is the key prefix inside the bucket ('' for none).
 */
final readonly class StorageSettings
{
    public const DRIVERS = ['sftp', 'ftp', 's3'];

    // Checked on save, and again wherever these end up in a shell script on a VPS.
    public const HOST_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/';

    public const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    public const ROOT_PATTERN = '/^\/?[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/';

    // S3: scheme + host (+ port), nothing after it. Bucket names as S3 allows them (no dots
    // at either end), regions like eu-central-1, fsn1 or auto (R2).
    public const ENDPOINT_PATTERN = '/^https?:\/\/[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::\d{1,5})?\/?$/';

    public const BUCKET_PATTERN = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/';

    public const REGION_PATTERN = '/^[a-z0-9-]{1,40}$/';

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
        public ?string $endpoint = null,
        public ?string $region = null,
        public ?string $bucket = null,
        public bool $pathStyle = false,
    ) {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException("Unknown world storage driver [{$driver}].");
        }
    }

    public static function defaultPort(string $driver): int
    {
        return match ($driver) {
            'ftp' => 21,
            's3' => 443,
            default => 22,
        };
    }

    /**
     * S3: the host and port requests go to (AWS's regional endpoint when none is set).
     *
     * @return array{0: string, 1: int}
     */
    public static function endpointHostAndPort(?string $endpoint, ?string $region): array
    {
        if ($endpoint === null || $endpoint === '') {
            return ['s3.'.($region ?: 'us-east-1').'.amazonaws.com', 443];
        }

        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);

        return [$host, is_int($port) ? $port : ($scheme === 'http' ? 80 : 443)];
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
            endpoint: $row->endpoint,
            region: $row->region,
            bucket: $row->bucket,
            pathStyle: $row->path_style,
        );
    }

    /**
     * @param  array<string, mixed>  $env  config('world_storage.env')
     */
    public static function fromEnv(array $env): ?self
    {
        $driver = in_array($env['driver'] ?? null, self::DRIVERS, true) ? $env['driver'] : 'sftp';

        if ($driver === 's3') {
            if (! is_string($env['bucket'] ?? null) || ! is_string($env['username'] ?? null)) {
                return null;
            }

            $endpoint = is_string($env['endpoint'] ?? null) ? rtrim($env['endpoint'], '/') : null;
            $region = is_string($env['region'] ?? null) ? $env['region'] : 'us-east-1';
            [$host, $port] = self::endpointHostAndPort($endpoint, $region);

            return new self(
                driver: 's3',
                host: $host,
                port: $port,
                username: $env['username'],
                password: is_string($env['password'] ?? null) ? $env['password'] : null,
                privateKey: null,
                root: is_string($env['root'] ?? null) ? trim($env['root'], '/') : 'minecraft-worlds',
                ftps: false,
                hostKey: null,
                endpoint: $endpoint,
                region: $region,
                bucket: $env['bucket'],
                pathStyle: (bool) ($env['path_style'] ?? false),
            );
        }

        if (! is_string($env['host'] ?? null) || ! is_string($env['username'] ?? null)) {
            return null;
        }

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
            $this->privateKey, $this->root, $this->ftps, $hostKey,
            $this->endpoint, $this->region, $this->bucket, $this->pathStyle);
    }

    public function isS3(): bool
    {
        return $this->driver === 's3';
    }

    /**
     * Usable for starts and stops: SFTP needs a pinned host key, FTP a password, S3 a bucket,
     * a region and both keys.
     */
    public function isComplete(): bool
    {
        return match ($this->driver) {
            'sftp' => $this->hostKey !== null && ($this->password !== null || $this->privateKey !== null),
            's3' => $this->bucket !== null && $this->region !== null && $this->password !== null,
            default => $this->password !== null,
        };
    }

    /**
     * Where an archive path lives: the object key in the bucket (prefix included). Other
     * drivers address paths relative to the base folder, so this is S3 only.
     */
    public function objectKey(string $path): string
    {
        $prefix = trim($this->root, '/');

        return $prefix === '' ? $path : $prefix.'/'.$path;
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
     * The options for an AWS S3Client (the disk's and the pre-signer's).
     *
     * @return array<string, mixed>
     */
    public function s3ClientConfig(): array
    {
        return array_filter([
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => ['key' => $this->username, 'secret' => (string) $this->password],
            'endpoint' => $this->endpoint !== null && $this->endpoint !== '' ? $this->endpoint : null,
            'use_path_style_endpoint' => $this->pathStyle,
            // The SDK adds CRC checksums to every upload since 3.337, which several
            // S3-compatible services (and pre-signed part uploads) refuse.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'http' => ['connect_timeout' => 15, 'timeout' => 60],
        ], fn ($value) => $value !== null);
    }

    /**
     * A Laravel filesystem disk config (league/flysystem-sftp-v3, -ftp or -aws-s3-v3).
     *
     * @return array<string, mixed>
     */
    public function diskConfig(): array
    {
        if ($this->driver === 's3') {
            return [
                ...$this->s3ClientConfig(),
                'driver' => 's3',
                'key' => $this->username,
                'secret' => (string) $this->password,
                'bucket' => $this->bucket,
                'root' => trim($this->root, '/'),
                'throw' => true,
            ];
        }

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
