<?php

namespace App\Servers;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Cloud\ServerSpec;
use App\Enums\RunStatus;
use App\Jobs\ProvisionServerRun;
use App\Jobs\StopServerRun;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The run state machine: every status change of a ServerRun happens here.
 *
 *   start()     → provisioning (and queues ProvisionServerRun)
 *   provision() → booting, or failed when the provider refuses
 *   advance()   → starting once the VPS runs, online once Minecraft takes connections
 *   requestStop() → stopping (and queues StopServerRun)
 *   stop()      → saves the world over SSH, deletes the VPS → stopped;
 *                 stop_failed (VPS kept, alert sent) when either step fails
 *   abandon()   → failed, VPS deleted (never came online: nothing to save)
 */
class ServerLauncher
{
    public function __construct(
        private readonly CloudProvider $cloud,
        private readonly MinecraftProbe $probe,
        private readonly RemoteShell $shell,
        private readonly Alerter $alerter,
    ) {}

    /**
     * @throws StartRefused
     */
    public function start(Server $server, ?User $user, ?string $ip): ServerRun
    {
        try {
            $run = Cache::lock('mvm:start', 15)->block(5, fn (): ServerRun => DB::transaction(function () use ($server, $user, $ip): ServerRun {
                if ($server->activeRun()->exists()) {
                    throw StartRefused::alreadyRunning($server->name);
                }

                $cap = (int) config('minecraft.max_running');

                if (ServerRun::query()->active()->count() >= $cap) {
                    throw StartRefused::capReached($cap);
                }

                $run = new ServerRun;
                $run->server_id = $server->id;
                $run->active_server_id = $server->id;
                $run->started_by = $user?->id;
                $run->started_from_ip = $ip;
                $run->status = RunStatus::Provisioning;
                $run->save();

                return $run;
            }));
        } catch (LockTimeoutException) {
            throw StartRefused::busy();
        } catch (UniqueConstraintViolationException) {
            // The unique active_server_id caught a start the lock didn't (e.g. a lock-less cache).
            throw StartRefused::alreadyRunning($server->name);
        }

        ProvisionServerRun::dispatch($run->id);

        return $run;
    }

    /**
     * Ask the provider for the VPS. Runs in the queued ProvisionServerRun job.
     */
    public function provision(ServerRun $run): void
    {
        if ($run->status !== RunStatus::Provisioning) {
            return;
        }

        $server = $run->server;

        try {
            $vps = $this->cloud->createServer(new ServerSpec(
                name: "mvm-{$server->slug}-{$run->id}",
                serverType: $server->server_type,
                location: $server->location,
                minecraftServerId: $server->id,
                userData: CloudInit::for($server),
            ));
        } catch (CloudException $e) {
            $this->fail($run, "The cloud provider refused to create the VPS: {$e->getMessage()}");

            return;
        }

        // A stop or a boot timeout may have ended the run while the provider was busy creating
        // the VPS: then nobody wants it, so it goes straight away instead of waiting for the sweep.
        $run->refresh();

        if ($run->status !== RunStatus::Provisioning) {
            $this->cloud->deleteServer($vps->id);

            return;
        }

        $run->provider_server_id = $vps->id;
        $run->ipv4 = $vps->ipv4;
        $run->status = RunStatus::Booting;
        $run->checked_at = now();
        $run->save();
    }

    /**
     * Move every booting/starting run on, at most once per check interval each.
     * Called on page views (so a watched start feels quick) and every minute by servers:advance.
     */
    public function advanceActive(bool $force = false): void
    {
        $interval = (int) config('minecraft.check_interval');

        ServerRun::query()
            ->active()
            ->whereIn('status', [RunStatus::Booting, RunStatus::Starting])
            ->when(! $force, fn ($query) => $query->where(fn ($q) => $q
                ->whereNull('checked_at')
                ->orWhere('checked_at', '<=', now()->subSeconds($interval))))
            ->with('server')
            ->get()
            ->each(function (ServerRun $run): void {
                try {
                    $this->advance($run);
                } catch (CloudException $e) {
                    // A provider hiccup isn't a failed start: try again next time.
                    Log::warning("Couldn't check run {$run->id}: {$e->getMessage()}");
                    $run->checked_at = now();
                    $run->save();
                }
            });
    }

    /**
     * @throws CloudException
     */
    public function advance(ServerRun $run): void
    {
        $run->checked_at = now();

        if ($run->status === RunStatus::Booting && $run->provider_server_id !== null) {
            $vps = $this->cloud->findServer($run->provider_server_id);

            if ($vps === null) {
                $this->fail($run, 'The VPS disappeared while it was booting.');

                return;
            }

            $run->ipv4 = $vps->ipv4 ?? $run->ipv4;

            if ($vps->isRunning()) {
                $run->status = RunStatus::Starting;
            }
        }

        if ($run->status === RunStatus::Starting && $run->ipv4 !== null
            && $this->probe->acceptsConnections($run->ipv4, (int) config('minecraft.port'))) {
            $run->status = RunStatus::Online;
            $run->online_at = now();
        }

        $run->save();
    }

    /**
     * Ask for a stop: marks the run "stopping" and queues StopServerRun, which saves the
     * world and deletes the VPS. False when there's nothing to stop (ended, still being
     * created, or already stopping).
     *
     * @param  string  $reason  idle, admin, starter
     * @param  bool  $force  skip the world save (an admin's "delete without saving" after a failed stop)
     */
    public function requestStop(ServerRun $run, string $reason, bool $force = false): bool
    {
        $stoppable = $force
            ? [RunStatus::StopFailed]
            : [RunStatus::Booting, RunStatus::Starting, RunStatus::Online, RunStatus::StopFailed];

        $updated = ServerRun::query()
            ->whereKey($run->id)
            ->whereNotNull('active_server_id')
            ->whereIn('status', $stoppable)
            ->update(['status' => RunStatus::Stopping, 'stop_reason' => $reason, 'updated_at' => now()]);

        if ($updated === 0) {
            return false;
        }

        $run->refresh();
        StopServerRun::dispatch($run->id, $force);

        return true;
    }

    /**
     * Runs in StopServerRun. A run that was online gets a clean Minecraft stop over SSH first
     * (`systemctl stop`, which saves the world). If that or the delete fails, the VPS is kept
     * and the run is marked stop_failed with an alert: a world is never lost to save money.
     */
    public function stop(ServerRun $run, bool $force = false): void
    {
        if ($run->status !== RunStatus::Stopping) {
            return;
        }

        $server = $run->server;

        if (! $force && $run->online_at !== null && $run->ipv4 !== null && $run->provider_server_id !== null) {
            try {
                $result = $this->shell->run($run->ipv4, CloudInit::stopCommand($server), 360);
            } catch (RemoteShellException $e) {
                $this->stopFailed($run, "Couldn't reach the VPS to save the world: {$e->getMessage()}");

                return;
            }

            if (! $result->succeeded()) {
                $output = trim($result->output);
                $this->stopFailed($run, "Minecraft didn't stop cleanly (exit {$result->exitCode}".($output !== '' ? ": {$output}" : '').').');

                return;
            }
        }

        if ($run->provider_server_id !== null) {
            try {
                $this->cloud->deleteServer($run->provider_server_id);
            } catch (CloudException $e) {
                $this->stopFailed($run, "The world was saved, but the VPS couldn't be deleted: {$e->getMessage()}");

                return;
            }
        }

        $this->end($run, RunStatus::Stopped);
    }

    /**
     * End a run that never came online (boot timeout): delete the VPS, mark it failed.
     *
     * @throws CloudException when the provider won't delete it (the run stays as it was, for the next check)
     */
    public function abandon(ServerRun $run, string $error): void
    {
        if ($run->provider_server_id !== null) {
            $this->cloud->deleteServer($run->provider_server_id);
        }

        $run->stop_reason = 'boot_timeout';
        $this->fail($run, $error);
    }

    /**
     * The stop didn't finish. The VPS is kept and still counts towards the cap; an admin is
     * told once. The sweep tries again every 10 minutes, and an admin can retry or delete it.
     */
    public function stopFailed(ServerRun $run, string $error): void
    {
        // A retry keeps the first failure's error until it succeeds: alert once, not every 10 minutes.
        $alreadyFailed = $run->error !== null;
        $run->status = RunStatus::StopFailed;
        $run->error = mb_substr($error, 0, 1000);
        $run->save();

        if (! $alreadyFailed) {
            $this->alerter->send(
                "{$run->server->name} couldn't be stopped",
                "{$error} The VPS is still running (and costing money) so the world isn't lost. "
                .'The watchdog tries again every 10 minutes. In Admin → Servers you can retry the stop, '
                .'or delete the VPS without saving.',
            );
        }
    }

    private function fail(ServerRun $run, string $error): void
    {
        $run->error = mb_substr($error, 0, 1000);
        $this->end($run, RunStatus::Failed);
    }

    private function end(ServerRun $run, RunStatus $status): void
    {
        $run->status = $status;
        $run->active_server_id = null;
        $run->ended_at = now();
        $run->save();
    }
}
