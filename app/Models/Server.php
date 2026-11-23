<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\Visibility;
use App\Minecraft\Software;
use Carbon\CarbonImmutable;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A Minecraft server's definition. Its VPS exists only while a run is active.
 *
 * @property int $id
 * @property string $name
 * @property string $slug lowercase letters, digits and dashes (names the VPS and the systemd unit)
 * @property string|null $description
 * @property Visibility $visibility public (home page, guests start it), private (signed in) or link only (#5462)
 * @property string|null $share_token a link-only server's share token (encrypted; Admin shows the link)
 * @property string|null $share_token_hash its SHA-256, for the lookup
 * @property string|null $icon_url
 * @property string $server_type
 * @property string $location
 * @property string $start_command
 * @property int $java_version
 * @property Software $software custom, or what the first start without a saved world installs (#5351)
 * @property string|null $minecraft_version the version it installs (null for custom and pack URLs)
 * @property string|null $pack_url a server-pack zip (#5352)
 * @property string|null $modrinth_project_id a Modrinth modpack (#5352)
 * @property string|null $modrinth_version_id its version
 * @property string|null $modrinth_title shown in the form
 * @property int|null $heap_mb a modpack's -Xms/-Xmx, from the server type's RAM
 * @property array<string, string>|null $properties server.properties values an admin changed (#5377)
 * @property list<array{uuid: string, name: string}>|null $whitelist written as whitelist.json when set
 * @property list<array{uuid: string, name: string, level: int}>|null $ops written as ops.json when set
 * @property string|null $run_sh written over the pack's run.sh when set
 * @property string|null $user_jvm_args written over the pack's user_jvm_args.txt when set
 * @property int $idle_minutes minutes with nobody on before the watchdog stops it
 * @property int|null $current_world_archive_id the archive the next start downloads (#5288)
 * @property string|null $subdomain its name inside the DNS zone, e.g. "atm9" → atm9.<domain> (#5338)
 * @property bool $wake_on_join while stopped, its name points at the waker and a join starts it (#5620)
 * @property CarbonImmutable|null $eula_accepted_at when an admin accepted the Minecraft EULA for it (#5367)
 * @property int|null $eula_accepted_by who did
 * @property-read User|null $eulaAcceptor
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServerRun|null $activeRun
 * @property-read ServerRun|null $latestRun
 * @property-read WorldArchive|null $currentWorldArchive
 */
#[Fillable(['name', 'slug', 'description', 'visibility', 'icon_url', 'server_type', 'location', 'start_command', 'java_version', 'idle_minutes', 'subdomain', 'wake_on_join', 'software', 'minecraft_version', 'pack_url', 'modrinth_project_id', 'modrinth_version_id', 'modrinth_title', 'heap_mb', 'properties', 'whitelist', 'ops', 'run_sh', 'user_jvm_args'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['software' => 'custom', 'visibility' => 'private'];

    /** @var list<string> */
    protected $hidden = ['share_token', 'share_token_hash'];

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

    /**
     * A link-only server always has a share token, and no other server has one: switching
     * away from Link only kills the link, and switching back makes a new one (#5462).
     */
    protected static function booted(): void
    {
        static::saving(function (Server $server): void {
            if ($server->visibility !== Visibility::Link) {
                $server->share_token = null;
                $server->share_token_hash = null;
            } elseif ($server->share_token_hash === null) {
                $server->fillShareToken();
            }
        });
    }

    /**
     * A new share link; the old one stops working at once. Saves.
     */
    public function regenerateShareToken(): void
    {
        $this->fillShareToken();
        $this->save();
    }

    private function fillShareToken(): void
    {
        $token = Str::random(40);
        $this->share_token = $token;
        $this->share_token_hash = self::hashShareToken($token);
    }

    public static function hashShareToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The link-only server this share token opens, if any.
     */
    public static function findByShareToken(string $token): ?self
    {
        return self::query()
            ->where('visibility', Visibility::Link)
            ->where('share_token_hash', self::hashShareToken($token))
            ->first();
    }

    public function shareUrl(): ?string
    {
        return $this->visibility === Visibility::Link && $this->share_token !== null
            ? route('share.show', $this->share_token)
            : null;
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
            'visibility' => Visibility::class,
            'share_token' => 'encrypted',
            'software' => Software::class,
            'properties' => 'array',
            'whitelist' => 'array',
            'ops' => 'array',
            'eula_accepted_at' => 'datetime',
            'java_version' => 'integer',
            'idle_minutes' => 'integer',
            'wake_on_join' => 'boolean',
            'heap_mb' => 'integer',
            'current_world_archive_id' => 'integer',
        ];
    }
}
