<?php

namespace App\Servers;

use App\Enums\RunStatus;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Servers as the pages show them: status, address once online, the last error.
 */
class ServerPresenter
{
    /**
     * @param  Builder<Server>  $query
     * @return Collection<int, array<string, mixed>>
     */
    public static function list(Builder $query): Collection
    {
        return $query->with(['activeRun', 'latestRun'])
            ->orderBy('name')
            ->get()
            ->map(fn (Server $server): array => self::one($server))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(Server $server): array
    {
        $status = $server->status();
        $run = $server->activeRun;
        $latest = $server->latestRun;

        return [
            'id' => $server->id,
            'name' => $server->name,
            'slug' => $server->slug,
            'description' => $server->description,
            'icon_url' => $server->icon_url,
            'is_public' => $server->is_public,
            'status' => $status->value,
            'status_label' => $status->label(),
            'address' => $status === RunStatus::Online && $run?->ipv4 !== null
                ? $run->ipv4.((int) config('minecraft.port') === 25565 ? '' : ':'.config('minecraft.port'))
                : null,
            'error' => $run === null && $latest?->status === RunStatus::Failed ? $latest->error : null,
            'can_start' => $run === null,
        ];
    }
}
