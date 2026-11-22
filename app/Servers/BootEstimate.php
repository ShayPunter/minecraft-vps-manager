<?php

namespace App\Servers;

use App\Minecraft\Software;
use App\Models\Server;
use App\Models\ServerRun;
use Illuminate\Database\Eloquent\Builder;

/**
 * How long a start usually takes, Start pressed → Online (#5379): the median of the
 * server's last 5 starts that came online, else of the newest starts across all servers,
 * else nothing. A first start that installs Vanilla/Paper/Fabric (#5351) downloads and
 * sets everything up, so installs and ordinary starts are counted apart.
 */
class BootEstimate
{
    /** Own starts the median is taken over. */
    public const OWN_SAMPLE = 5;

    /** Starts across all servers when this one has none of the kind yet. */
    public const ALL_SAMPLE = 20;

    /**
     * Whether the next (or current) start installs the server's software: an installable
     * type with no saved world to download (ServerLauncher::prepare()).
     */
    public static function installs(Server $server): bool
    {
        return $server->software !== Software::Custom && $server->current_world_archive_id === null;
    }

    /**
     * Median seconds from Start to Online for a start of this server, null without history.
     */
    public static function typicalSeconds(Server $server): ?int
    {
        $installs = self::installs($server);

        return self::median(self::durations(self::ofKind($installs)->where('server_id', $server->id), self::OWN_SAMPLE))
            ?? self::median(self::durations(self::ofKind($installs), self::ALL_SAMPLE));
    }

    /**
     * Runs that came online and installed (no world archive, installable software), or the rest.
     *
     * @return Builder<ServerRun>
     */
    private static function ofKind(bool $installs): Builder
    {
        $query = ServerRun::query()->whereNotNull('online_at');
        $installable = array_map(fn (Software $software): string => $software->value, Software::installable());

        return $installs
            ? $query->whereNull('world_archive_id')->whereHas('server', fn (Builder $q) => $q->whereIn('software', $installable))
            : $query->where(fn (Builder $q) => $q
                ->whereNotNull('world_archive_id')
                ->orWhereHas('server', fn (Builder $s) => $s->whereNotIn('software', $installable)));
    }

    /**
     * @param  Builder<ServerRun>  $query
     * @return list<int>
     */
    private static function durations(Builder $query, int $limit): array
    {
        return array_values($query->orderByDesc('online_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'created_at', 'online_at'])
            ->map(fn (ServerRun $run): int => max(0, (int) $run->created_at?->diffInSeconds($run->online_at)))
            ->all());
    }

    /**
     * @param  list<int>  $values
     */
    public static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : intdiv($values[$middle - 1] + $values[$middle], 2);
    }
}
