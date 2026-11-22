<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin → World storage (#5288). One row at most. Only WorldStorageController writes it,
 * so nothing is mass assigned.
 *
 * @property int $id
 * @property string $driver sftp, ftp or s3
 * @property string $host S3: the endpoint's host (messages only)
 * @property int $port
 * @property string $username S3: the access key
 * @property string|null $password encrypted with APP_KEY (S3: the secret key)
 * @property string|null $private_key encrypted with APP_KEY (SFTP only)
 * @property string $root base folder on the storage server
 * @property bool $ftps FTP only: TLS on (explicit AUTH TLS)
 * @property string|null $host_key SFTP only: the pinned public host key ("ssh-ed25519 AAAA…")
 * @property string|null $endpoint S3 only: https://host[:port], null = AWS (#5347)
 * @property string|null $region S3 only
 * @property string|null $bucket S3 only
 * @property bool $path_style S3 only: bucket in the path, not the host name (MinIO-style servers)
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WorldStorageSetting extends Model
{
    /**
     * Never in toArray()/JSON, so no page, log or API answer can carry a secret.
     *
     * @var list<string>
     */
    protected $hidden = ['password', 'private_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'ftps' => 'boolean',
            'path_style' => 'boolean',
            'password' => 'encrypted',
            'private_key' => 'encrypted',
        ];
    }
}
