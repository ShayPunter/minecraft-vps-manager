<?php

namespace App\Servers;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Cloud\ServerSpec;
use App\Enums\RunStatus;
use App\Jobs\ProvisionServerRun;
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
 *   stop()      → stopping → stopped (VPS deleted)
 */
class ServerLauncher
{
    public function __construct(
        private readonly CloudProvider $cloud,
        private readonly MinecraftProbe $probe,
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
     * Delete the VPS and end the run.
     *
     * @throws CloudException when the provider won't delete it (the run stays "stopping")
     */
    public function stop(ServerRun $run): void
    {
        if ($run->active_server_id === null) {
            return;
        }

        $run->status = RunStatus::Stopping;
        $run->save();

        if ($run->provider_server_id !== null) {
            $this->cloud->deleteServer($run->provider_server_id);
        }

        $this->end($run, RunStatus::Stopped);
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
