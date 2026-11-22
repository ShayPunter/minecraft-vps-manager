<?php

namespace App\Servers;

use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Servers as the pages show them: status, address and players once online, the last
 * error, and what the viewer may do (start; stop if they're an admin or started it).
 */
class ServerPresenter
{
    /** A run in one of these can be stopped by hand (provisioning can't: no VPS yet). */
    private const STOPPABLE = [RunStatus::Booting, RunStatus::Starting, RunStatus::Online, RunStatus::StopFailed];

    /**
     * @param  Builder<Server>  $query
     * @return Collection<int, array<string, mixed>>
     */
    public static function list(Builder $query, ?User $viewer = null): Collection
    {
        return $query->with(['activeRun', 'latestRun'])
            ->orderBy('name')
            ->get()
            ->map(fn (Server $server): array => self::one($server, $viewer))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(Server $server, ?User $viewer = null): array
    {
        $status = $server->status();
        $run = $server->activeRun;
        $latest = $server->latestRun;

        [$error, $errorLabel] = match (true) {
            $run?->status === RunStatus::StopFailed => [$run->error, 'Stop failed'],
            $run === null && $latest?->status === RunStatus::Failed => [$latest->error, 'Last start failed'],
            default => [null, null],
        };

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
            'players_online' => $status === RunStatus::Online ? $run?->players_online : null,
            'idle_minutes' => $server->idle_minutes,
            'error' => $error,
            'error_label' => $errorLabel,
            'can_start' => $run === null,
            'can_stop' => $run !== null && self::mayStop($run, $viewer),
            'can_force_delete' => $run?->status === RunStatus::StopFailed && $viewer?->isAdmin() === true,
        ];
    }

    /**
     * Admins can stop any server; anyone signed in can stop a run they started themselves.
     */
    public static function mayStop(ServerRun $run, ?User $viewer): bool
    {
        if ($viewer === null || ! in_array($run->status, self::STOPPABLE, true)) {
            return false;
        }

        return $viewer->isAdmin() || ($run->started_by !== null && $run->started_by === $viewer->id);
    }
}
