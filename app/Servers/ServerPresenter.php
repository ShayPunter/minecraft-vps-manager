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

    /** What non-admins see instead of a run's error (#5527). */
    public const PUBLIC_ERROR = 'Something went wrong. An admin has the details.';

    /** Between Start and Online. */
    private const STARTING = [RunStatus::Provisioning, RunStatus::Booting, RunStatus::Starting];

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

        // The error text holds script output, SSH/provider messages, storage user@host and
        // VPS addresses (#5527): admins only. Everyone else gets the label and a plain line.
        $error = self::errorFor($error, $viewer);

        return [
            'id' => $server->id,
            'name' => $server->name,
            'slug' => $server->slug,
            'description' => $server->description,
            'icon_url' => $server->icon_url,
            'visibility' => $server->visibility->value,
            'status' => $status->value,
            'status_label' => $status->label(),
            // The server's name once its record points at this VPS (#5338), else the IP.
            'address' => $status === RunStatus::Online && $run?->ipv4 !== null
                ? ($run->dns_record_id !== null && $run->dns_name !== null ? $run->dns_name : $run->ipv4)
                    .((int) config('minecraft.port') === 25565 ? '' : ':'.config('minecraft.port'))
                : null,
            'ip_address' => $status === RunStatus::Online ? $run?->ipv4 : null,
            // Admins only: why the name isn't set (players just see the IP).
            'dns_error' => $viewer?->isAdmin() === true ? $run?->dns_error : null,
            'players_online' => $status === RunStatus::Online ? $run?->players_online : null,
            'idle_minutes' => $server->idle_minutes,
            // When the watchdog stops it if nobody is on (#5378), and the seconds left from
            // this answer, so the page counts down without trusting the viewer's clock.
            // 0 = due: the watchdog's next minute stops it. Null unless online.
            ...self::idleStop($status === RunStatus::Online ? $run : null, $server->idle_minutes),
            ...self::bootEstimate($server, $run, $status),
            'error' => $error,
            'error_label' => $errorLabel,
            'can_start' => $run === null,
            'can_stop' => $run !== null && self::mayStop($run, $viewer),
            'can_force_delete' => $run?->status === RunStatus::StopFailed && $viewer?->isAdmin() === true,
        ];
    }

    /**
     * A run's error as this viewer may see it: the text for admins, a plain line for
     * everyone else (#5527), null when there's none.
     */
    public static function errorFor(?string $error, ?User $viewer): ?string
    {
        if ($error === null) {
            return null;
        }

        return $viewer?->isAdmin() === true ? $error : self::PUBLIC_ERROR;
    }

    /**
     * @return array{stops_at: string|null, stops_in_seconds: int|null}
     */
    private static function idleStop(?ServerRun $run, int $idleMinutes): array
    {
        $stopsAt = $run?->idleStopsAt($idleMinutes);

        return [
            'stops_at' => $stopsAt?->toIso8601String(),
            'stops_in_seconds' => $stopsAt === null ? null : max(0, (int) now()->diffInSeconds($stopsAt, false)),
        ];
    }

    /**
     * How long a start usually takes (#5379), sent while it can be started or is starting,
     * and how long this start has been going, from the server's clock.
     *
     * @return array{typical_boot_seconds: int|null, boot_elapsed_seconds: int|null}
     */
    private static function bootEstimate(Server $server, ?ServerRun $run, RunStatus $status): array
    {
        $starting = $run !== null && in_array($status, self::STARTING, true);

        if ($run !== null && ! $starting) {
            return ['typical_boot_seconds' => null, 'boot_elapsed_seconds' => null];
        }

        return [
            'typical_boot_seconds' => BootEstimate::typicalSeconds($server),
            'boot_elapsed_seconds' => $starting && $run->created_at !== null
                ? max(0, (int) $run->created_at->diffInSeconds(now()))
                : null,
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
