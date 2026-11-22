<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin → World storage (#5288). One row at most. Only WorldStorageController writes it,
 * so nothing is mass assigned.
 *
 * @property int $id
 * @property string $driver sftp or ftp
 * @property string $host
 * @property int $port
 * @property string $username
 * @property string|null $password encrypted with APP_KEY
 * @property string|null $private_key encrypted with APP_KEY (SFTP only)
 * @property string $root base folder on the storage server
 * @property bool $ftps FTP only: TLS on (explicit AUTH TLS)
 * @property string|null $host_key SFTP only: the pinned public host key ("ssh-ed25519 AAAA…")
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
            'password' => 'encrypted',
            'private_key' => 'encrypted',
        ];
    }
}
