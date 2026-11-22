<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a run's log (#5367): the VPS's setup output, prepare.sh's output, Minecraft's
 * console (posted by the log shipper on the VPS), or what the app pulled over SSH before
 * deleting a VPS that never came online. Admins only: Minecraft logs player names and IPs.
 *
 * @property int $id
 * @property int $server_run_id
 * @property string $source setup, prepare, minecraft or fallback
 * @property string $line
 * @property CarbonImmutable|null $created_at
 */
class ServerRunLog extends Model
{
    public const UPDATED_AT = null;

    public const SOURCES = ['setup', 'prepare', 'minecraft', 'fallback'];

    /**
     * @return BelongsTo<ServerRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ServerRun::class, 'server_run_id');
    }
}
