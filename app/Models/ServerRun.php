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
 * @property int|null $players_online what the last server-list ping said (null: no answer)
 * @property CarbonImmutable|null $last_players_at the last ping that counted a player
 * @property string|null $stop_reason idle, admin, starter, boot_timeout
 * @property CarbonImmutable|null $long_run_alerted_at
 * @property string|null $ssh_private_key this run's OpenSSH private key, encrypted with APP_KEY; wiped once the VPS is gone (#5324)
 * @property int|null $provider_ssh_key_id its public half at the provider
 * @property CarbonImmutable|null $prepare_queued_at PrepareServerRun was queued (#5288); null again to retry
 * @property CarbonImmutable|null $prepared_at the world is in place and Minecraft was started
 * @property int|null $world_archive_id the archive this run started from (null: a new world)
 * @property int|null $saved_world_archive_id the archive this run's stop saved
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Server $server
 * @property-read User|null $starter
 */
class ServerRun extends Model
{
    /**
     * Never in toArray()/JSON, so no page, log or API answer can carry the key.
     *
     * @var list<string>
     */
    protected $hidden = ['ssh_private_key'];

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
            'players_online' => 'integer',
            'last_players_at' => 'datetime',
            'long_run_alerted_at' => 'datetime',
            'ssh_private_key' => 'encrypted',
            'provider_ssh_key_id' => 'integer',
            'prepare_queued_at' => 'datetime',
            'prepared_at' => 'datetime',
            'world_archive_id' => 'integer',
            'saved_world_archive_id' => 'integer',
        ];
    }
}
