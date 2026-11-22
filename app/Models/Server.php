<?php

namespace App\Models;

use App\Enums\RunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Minecraft server's definition. Its VPS exists only while a run is active.
 *
 * @property int $id
 * @property string $name
 * @property string $slug lowercase letters, digits and dashes (names the VPS and the systemd unit)
 * @property string|null $description
 * @property bool $is_public listed on the home page; guests may start it
 * @property string|null $icon_url
 * @property string $server_type
 * @property string $location
 * @property string $start_command
 * @property int $java_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServerRun|null $activeRun
 * @property-read ServerRun|null $latestRun
 */
#[Fillable(['name', 'slug', 'description', 'is_public', 'icon_url', 'server_type', 'location', 'start_command', 'java_version'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * @return HasMany<ServerRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ServerRun::class);
    }

    /**
     * @return HasOne<ServerRun, $this>
     */
    public function activeRun(): HasOne
    {
        return $this->hasOne(ServerRun::class, 'active_server_id');
    }

    /**
     * @return HasOne<ServerRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ServerRun::class)->latestOfMany();
    }

    public function status(): RunStatus
    {
        return $this->activeRun !== null ? $this->activeRun->status : RunStatus::Stopped;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'java_version' => 'integer',
        ];
    }
}
