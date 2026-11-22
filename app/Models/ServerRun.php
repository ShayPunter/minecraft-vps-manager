<?php

namespace App\Models;

use App\Enums\RunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One VPS lifetime. Only App\Servers\ServerLauncher changes it, so nothing is mass assigned.
 *
 * @property int $id
 * @property int $server_id
 * @property int|null $active_server_id equal to server_id while active, null once ended
 * @property int|null $started_by
 * @property string|null $started_from_ip
 * @property RunStatus $status
 * @property int|null $provider_server_id
 * @property string|null $ipv4
 * @property string|null $error
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $online_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server $server
 * @property-read User|null $starter
 */
class ServerRun extends Model
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
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @param  Builder<ServerRun>  $query
     * @return Builder<ServerRun>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('active_server_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'checked_at' => 'datetime',
            'online_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
