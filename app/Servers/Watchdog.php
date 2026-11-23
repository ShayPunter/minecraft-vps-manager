<?php

namespace App\Servers;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Dns\Dns;
use App\Enums\RunStatus;
use App\Models\ServerRun;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the bill down without losing a world (#5283).
 *
 *   watch() every minute: moves starting runs on, gives up on runs that never come online,
 *           and stops online servers nobody has played on for the server's idle minutes.
 *   sweep() every 10 minutes: deletes VPSes with the app's label that no active run knows,
 *           retries failed stops, deletes the app's DNS records no active run owns (#5338),
 *           and alerts once on runs older than max_run_hours.
 */
class Watchdog
{
    public function __construct(
        private readonly ServerLauncher $launcher,
        private readonly MinecraftProbe $probe,
        private readonly CloudProvider $cloud,
        private readonly Alerter $alerter,
        private readonly Dns $dns,
    ) {}

    public function watch(): void
    {
        $this->launcher->advanceActive(force: true);
        $this->abandonSlowStarts();
        $this->stopIdleServers();
    }

    public function sweep(): void
    {
        $this->failStuckStops();
        $this->retryFailedStops();
        $this->deleteOrphans();
        $this->deleteOrphanKeys();
        $this->dns->deleteOrphans();
        $this->alertLongRuns();
    }

    /**
     * Managed SSH keys no active run uses (#5324): left behind when the provider couldn't be
     * reached as a run ended. A key labelled with an active run's id is kept, so a run that's
     * still provisioning (key made, id not saved yet) isn't hit. Keys without the label, such
     * as one Shay added by hand, are never touched.
     */
    private function deleteOrphanKeys(): void
    {
        try {
            $keys = $this->cloud->managedSshKeys();
        } catch (CloudException $e) {
            Log::warning("SSH key sweep skipped: {$e->getMessage()}");

            return;
        }

        $active = ServerRun::query()->active()->get(['id', 'provider_ssh_key_id']);
        $knownKeyIds = $active->pluck('provider_ssh_key_id')->filter()->all();
        $activeRunIds = $active->pluck('id')->all();

        foreach ($keys as $key) {
            if (in_array($key->id, $knownKeyIds, true) || ($key->runId() !== null && in_array($key->runId(), $activeRunIds, true))) {
                continue;
            }

            try {
                $this->cloud->deleteSshKey($key->id);
            } catch (CloudException $e) {
                Log::warning("Couldn't delete orphan SSH key {$key->id}: {$e->getMessage()}");
            }
        }
    }

    private function abandonSlowStarts(): void
    {
        $minutes = (int) config('minecraft.boot_timeout_minutes');

        ServerRun::query()
            ->active()
            ->whereIn('status', [RunStatus::Provisioning, RunStatus::Booting, RunStatus::Starting])
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->with('server')
            ->get()
            ->each(function (ServerRun $run) use ($minutes): void {
                // A first start that installs a modpack gets longer (#5352).
                if ($run->server->software->isPack() && $run->server->current_world_archive_id === null) {
                    $minutes = max($minutes, (int) config('minecraft.pack_boot_timeout_minutes'));

                    if ($run->created_at !== null && $run->created_at->gt(now()->subMinutes($minutes))) {
                        return;
                    }
                }

                $error = "It wasn't online within {$minutes} minutes of starting, so the VPS was deleted. "
                    .'Check the start command and the server type.';

                try {
                    $this->launcher->abandon($run, $error);
                } catch (CloudException $e) {
                    Log::warning("Couldn't delete the VPS of slow run {$run->id}: {$e->getMessage()}");

                    return;
                }

                $this->alerter->send("{$run->server->name} didn't start", $error);
            });
    }

    private function stopIdleServers(): void
    {
        $port = (int) config('minecraft.port');

        ServerRun::query()
            ->active()
            ->where('status', RunStatus::Online)
            ->whereNotNull('ipv4')
            ->with('server')
            ->get()
            ->each(function (ServerRun $run) use ($port): void {
                $players = $this->probe->playersOnline((string) $run->ipv4, $port);

                $run->players_online = $players;
                $run->checked_at = now();

                if ($players !== null && $players > 0) {
                    $run->last_players_at = now();
                }

                $run->save();

                // Idle time counts from when it came online (boot time never counts) or
                // from the last minute someone was on, whichever is later. A server that
                // stops answering counts as idle: it's either crashed or about to be.
                // The pages count down to the same time (ServerPresenter, #5378).
                $stopsAt = $run->idleStopsAt($run->server->idle_minutes);

                if ($stopsAt !== null && $stopsAt->lessThanOrEqualTo(now())) {
                    $this->launcher->requestStop($run, 'idle');
                }
            });
    }

    /** A stop whose job never finished (the worker died) gets retried like a failed one. */
    private function failStuckStops(): void
    {
        ServerRun::query()
            ->active()
            ->where('status', RunStatus::Stopping)
            ->where('updated_at', '<=', now()->subMinutes((int) config('minecraft.stuck_stop_minutes')))
            ->with('server')
            ->get()
            ->each(fn (ServerRun $run) => $this->launcher->stopFailed($run, "The stop didn't finish (is the queue worker running?)."));
    }

    private function retryFailedStops(): void
    {
        ServerRun::query()
            ->active()
            ->where('status', RunStatus::StopFailed)
            ->get()
            ->each(fn (ServerRun $run) => $this->launcher->requestStop($run, $run->stop_reason ?? 'retry'));
    }

    private function deleteOrphans(): void
    {
        try {
            $vpses = $this->cloud->managedServers();
        } catch (CloudException $e) {
            Log::warning("Orphan sweep skipped: {$e->getMessage()}");

            return;
        }

        $active = ServerRun::query()->active()->get(['id', 'provider_server_id']);
        $knownVpsIds = $active->pluck('provider_server_id')->filter()->all();
        $activeRunIds = $active->pluck('id')->all();
        $deleted = [];

        foreach ($vpses as $vps) {
            // The VPS name ends in the run id (mvm-<slug>-<run>), so a VPS whose run is still
            // being provisioned (id not saved yet) isn't mistaken for an orphan.
            $runId = preg_match('/-(\d+)$/', $vps->name, $m) === 1 ? (int) $m[1] : null;

            if (in_array($vps->id, $knownVpsIds, true) || ($runId !== null && in_array($runId, $activeRunIds, true))) {
                continue;
            }

            try {
                $this->cloud->deleteServer($vps->id);
                $deleted[] = "{$vps->name} ({$vps->id})";
            } catch (CloudException $e) {
                Log::warning("Couldn't delete orphan VPS {$vps->id}: {$e->getMessage()}");
            }
        }

        if ($deleted !== []) {
            $this->alerter->send(
                'Deleted VPSes no server was using',
                'The sweep found and deleted: '.implode(', ', $deleted).'. No world was saved from them.',
            );
        }
    }

    private function alertLongRuns(): void
    {
        $hours = (int) config('minecraft.max_run_hours');

        ServerRun::query()
            ->active()
            ->whereNull('long_run_alerted_at')
            ->where('created_at', '<=', now()->subHours($hours))
            ->with('server')
            ->get()
            ->each(function (ServerRun $run) use ($hours): void {
                $run->long_run_alerted_at = now();
                $run->save();

                $this->alerter->send(
                    "{$run->server->name} has been running for {$hours} hours",
                    "It was started {$run->created_at?->diffForHumans()} and is {$run->status->label()}. "
                    .'If nobody is playing, stop it in Admin → Servers.',
                );
            });
    }
}
