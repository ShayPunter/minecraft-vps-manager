<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One archive of a server's folder on the world storage server (#5288). Only
 * App\WorldStorage\Archives writes these, so nothing is mass assigned.
 *
 * @property int $id
 * @property int $server_id
 * @property string $path relative to the storage server's base folder
 * @property int $size bytes
 * @property string $sha256
 * @property string $source upload or save
 * @property int|null $server_run_id the run whose stop saved it
 * @property int|null $uploaded_by
 * @property array<string, string>|null $files its server.properties, run.sh etc. as saved (#5377, ServerFiles); null before #5377
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server $server
 * @property-read User|null $uploader
 */
class WorldArchive extends Model
{
    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function fileName(): string
    {
        return basename($this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'server_run_id' => 'integer',
            'files' => 'array',
        ];
    }
}
