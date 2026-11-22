<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Minecraft\Software;
use Carbon\CarbonImmutable;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property Software $software custom, or what the first start without a saved world installs (#5351)
 * @property string|null $minecraft_version the version it installs (null for custom)
 * @property int $idle_minutes minutes with nobody on before the watchdog stops it
 * @property int|null $current_world_archive_id the archive the next start downloads (#5288)
 * @property string|null $subdomain its name inside the DNS zone, e.g. "atm9" → atm9.<domain> (#5338)
 * @property CarbonImmutable|null $eula_accepted_at when an admin accepted the Minecraft EULA for it (#5367)
 * @property int|null $eula_accepted_by who did
 * @property-read User|null $eulaAcceptor
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServerRun|null $activeRun
 * @property-read ServerRun|null $latestRun
 * @property-read WorldArchive|null $currentWorldArchive
 */
#[Fillable(['name', 'slug', 'description', 'is_public', 'icon_url', 'server_type', 'location', 'start_command', 'java_version', 'idle_minutes', 'subdomain', 'software', 'minecraft_version'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['software' => 'custom'];

    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    // One or more DNS labels: "atm9", "mc.atm9" (#5338).
    public const SUBDOMAIN_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

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
     * @return BelongsTo<User, $this>
     */
    public function eulaAcceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eula_accepted_by');
    }

    /**
     * @return HasOne<ServerRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ServerRun::class)->latestOfMany();
    }

    /**
     * @return HasMany<WorldArchive, $this>
     */
    public function worldArchives(): HasMany
    {
        return $this->hasMany(WorldArchive::class);
    }

    /**
     * @return BelongsTo<WorldArchive, $this>
     */
    public function currentWorldArchive(): BelongsTo
    {
        return $this->belongsTo(WorldArchive::class, 'current_world_archive_id');
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
            'software' => Software::class,
            'eula_accepted_at' => 'datetime',
            'java_version' => 'integer',
            'idle_minutes' => 'integer',
            'current_world_archive_id' => 'integer',
        ];
    }
}
