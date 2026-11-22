<?php

namespace App\Servers;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Cloud\ServerSpec;
use App\Dns\Dns;
use App\Enums\RunStatus;
use App\Jobs\PrepareServerRun;
use App\Jobs\ProvisionServerRun;
use App\Jobs\StopServerRun;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\WorldStorage\Archives;
use App\WorldStorage\StorageUnavailable;
use App\WorldStorage\WorldStorage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

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
        private readonly WorldStorage $storage,
        private readonly Archives $archives,
        private readonly Dns $dns,
    ) {}

    /**
     * @throws StartRefused
     */
    public function start(Server $server, ?User $user, ?string $ip): ServerRun
    {
        // Without world storage a stop couldn't save the world (#5288): don't start at all.
        try {
            $this->storage->settings();
        } catch (StorageUnavailable $e) {
            Log::warning("Start of {$server->slug} refused: {$e->getMessage()}");

            throw StartRefused::noWorldStorage();
        }

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
        // What the database says, not what the caller held: the saves below must never write
        // back a status that a stop or a timeout has changed since.
        $run->refresh();

        if ($run->status !== RunStatus::Provisioning) {
            return;
        }

        $server = $run->server;
        $name = "mvm-{$server->slug}-{$run->id}";

        // A key pair of its own for this VPS (#5324). The private half is saved (encrypted)
        // before the public half goes to the provider, so a key that exists there always has
        // a run that can log in with it, or gets cleaned up with that run.
        $keys = SshKeyPair::generate($name);
        $run->ssh_private_key = $keys->privateKey;
        $run->save();

        try {
            $sshKey = $this->cloud->createSshKey($name, $keys->publicKey, [
                CloudProvider::RUN_ID_LABEL => (string) $run->id,
            ]);
        } catch (CloudException $e) {
            $this->fail($run, "The cloud provider refused the VPS's SSH key: {$e->getMessage()}");

            return;
        }

        $run->provider_ssh_key_id = $sshKey->id;
        $run->save();

        try {
            $vps = $this->cloud->createServer(new ServerSpec(
                name: $name,
                serverType: $server->server_type,
                location: $server->location,
                minecraftServerId: $server->id,
                sshKeyIds: [$sshKey->id],
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
            $this->releaseKey($run, $sshKey->id);

            return;
        }

        $run->provider_server_id = $vps->id;
        $run->ipv4 = $vps->ipv4;
        $run->status = RunStatus::Booting;
        $run->checked_at = now();
        $run->save();

        $this->pointDns($run);
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
                // Once more now the VPS runs: covers an IP that came late, or a DNS hiccup at create.
                $run->save();
                $this->pointDns($run);
            }
        }

        // The VPS is up: the world goes in and Minecraft starts (PrepareServerRun, once).
        if ($run->status === RunStatus::Starting && $run->prepared_at === null) {
            $run->save();
            $this->queuePrepare($run);
            $run->refresh();

            if ($run->status !== RunStatus::Starting || $run->prepared_at === null) {
                return;
            }

            $run->checked_at = now();
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
        // Minecraft ran on it (prepare.sh started it, or a run from before #5288 came online).
        $started = $run->prepared_at !== null || $run->online_at !== null;

        if (! $force && $started && $run->ipv4 !== null && $run->provider_server_id !== null) {
            try {
                $result = $this->shell->run($run->ipv4, CloudInit::stopCommand($server), 360, $run->ssh_private_key);
            } catch (RemoteShellException $e) {
                $this->stopFailed($run, "Couldn't reach the VPS to save the world: {$e->getMessage()}");

                return;
            }

            if (! $result->succeeded()) {
                $output = trim($result->output);
                $this->stopFailed($run, "Minecraft didn't stop cleanly (exit {$result->exitCode}".($output !== '' ? ": {$output}" : '').').');

                return;
            }

            // A retried stop whose upload already worked (the delete failed) doesn't save twice.
            if ($run->saved_world_archive_id === null && ! $this->saveWorld($run)) {
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

    /**
     * Runs in PrepareServerRun once the VPS is up (#5288): put the storage login and
     * prepare.sh on it, which waits for cloud-init, downloads and checks the current world
     * and starts Minecraft. A VPS that can't be reached yet (sshd still starting) is tried
     * again on the next check; the boot timeout still applies. A script that fails gives up:
     * the VPS is deleted (Minecraft never ran there, so there's nothing to save).
     */
    public function prepare(ServerRun $run): void
    {
        $run->refresh();

        if ($run->status !== RunStatus::Starting || $run->prepared_at !== null || $run->ipv4 === null) {
            return;
        }

        $server = $run->server;
        $archive = $server->currentWorldArchive;

        try {
            $storage = $archive !== null ? $this->storage->settings() : null;
        } catch (StorageUnavailable $e) {
            $this->giveUp($run, "The saved world couldn't be downloaded: {$e->getMessage()}");

            return;
        }

        try {
            $this->shell->putFiles($run->ipv4, WorldSync::prepareFiles($server, $archive, $storage), $run->ssh_private_key);
            $result = $this->shell->run($run->ipv4, WorldSync::PREPARE_COMMAND, (int) config('minecraft.prepare_timeout'), $run->ssh_private_key);
        } catch (RemoteShellException $e) {
            Log::info("Run {$run->id}: VPS not ready for the world yet, trying again: {$e->getMessage()}");
            $this->retryPrepare($run);

            return;
        }

        if ($result->exitCode === WorldSync::EXIT_BUSY) {
            $this->retryPrepare($run);

            return;
        }

        if (! $result->succeeded()) {
            $output = mb_substr(trim($result->output), -500);
            $this->giveUp($run, "Setting up the server failed (exit {$result->exitCode}".($output !== '' ? ": {$output}" : '').').');

            return;
        }

        $run->refresh();

        if ($run->status !== RunStatus::Starting) {
            return;
        }

        $run->prepared_at = now();
        $run->world_archive_id = $archive?->id;
        $run->save();
    }

    private function queuePrepare(ServerRun $run): void
    {
        $queued = ServerRun::query()
            ->whereKey($run->id)
            ->whereNull('prepare_queued_at')
            ->update(['prepare_queued_at' => now()]);

        if ($queued === 1) {
            PrepareServerRun::dispatch($run->id);
        }
    }

    public function retryPrepare(ServerRun $run): void
    {
        ServerRun::query()->whereKey($run->id)->update(['prepare_queued_at' => null]);
    }

    /**
     * prepare() can't go on: delete the VPS and fail the run. If the provider won't delete it
     * now, the run stays as it is and the boot timeout deals with it.
     */
    private function giveUp(ServerRun $run, string $error): void
    {
        try {
            $this->abandon($run, $error);
        } catch (CloudException $e) {
            Log::warning("Run {$run->id}: couldn't delete the VPS after a failed setup ({$error}): {$e->getMessage()}");
            $run->error = mb_substr($error, 0, 1000);
            $run->save();
        }
    }

    /**
     * After the clean stop: save.sh zips the server folder, uploads it, reads it back and
     * compares the SHA-256. The app then checks the size on storage itself before the archive
     * becomes the server's current one. Any failure → stop_failed: the VPS (and the world on
     * it) is kept, and the next try saves again.
     */
    private function saveWorld(ServerRun $run): bool
    {
        $server = $run->server;
        $host = (string) $run->ipv4;

        try {
            $storage = $this->storage->settings();
        } catch (StorageUnavailable $e) {
            $this->stopFailed($run, "Minecraft stopped, but the world couldn't be saved: {$e->getMessage()}");

            return false;
        }

        $path = $this->archives->newPath($server, 'save.zip');

        try {
            $this->shell->putFiles($host, WorldSync::saveFiles($server, $storage), $run->ssh_private_key);
            $result = $this->shell->run($host, WorldSync::saveCommand($server, $path), (int) config('minecraft.save_timeout'), $run->ssh_private_key);
        } catch (RemoteShellException $e) {
            $this->stopFailed($run, "Minecraft stopped, but uploading the world failed: {$e->getMessage()}");

            return false;
        }

        if (! $result->succeeded()) {
            $output = mb_substr(trim($result->output), -500);
            $this->stopFailed($run, "Minecraft stopped, but uploading the world failed (exit {$result->exitCode}".($output !== '' ? ": {$output}" : '').').');

            return false;
        }

        try {
            $saved = WorldSync::parseSaveOutput($result->output);
        } catch (InvalidArgumentException $e) {
            $this->stopFailed($run, "Minecraft stopped, but the upload didn't finish: {$e->getMessage()}");

            return false;
        }

        if ($saved === null) {
            Log::info("Run {$run->id}: the server folder was empty, nothing to save.");

            return true;
        }

        try {
            $stored = $this->storage->disk($storage)->size($path);
        } catch (Throwable $e) {
            $this->stopFailed($run, "The world was uploaded, but the app couldn't check it on world storage: {$e->getMessage()}");

            return false;
        }

        if ($stored !== $saved['size']) {
            $this->stopFailed($run, "World storage has {$stored} bytes of the {$saved['size']} uploaded.");

            return false;
        }

        $archive = $this->archives->record($server, $path, $saved['size'], $saved['sha256'], 'save', $run);
        $run->saved_world_archive_id = $archive->id;
        $run->save();

        Log::info('World saved', ['server' => $server->slug, 'run' => $run->id, 'archive' => $archive->id, 'size' => $saved['size']]);

        return true;
    }

    private function fail(ServerRun $run, string $error): void
    {
        $run->error = mb_substr($error, 0, 1000);
        $this->end($run, RunStatus::Failed);
    }

    /**
     * The run is over and its VPS is gone (deleted, never made, or vanished): its key goes too.
     * A run that ends in stop_failed never comes here, so Retry stop can still log in.
     */
    private function end(ServerRun $run, RunStatus $status): void
    {
        $run->status = $status;
        $run->active_server_id = null;
        $run->ended_at = now();
        $run->save();

        $this->releaseKey($run, $run->provider_ssh_key_id);

        try {
            $this->dns->release($run);
        } catch (Throwable $e) {
            Log::warning("Run {$run->id}: DNS record not released, the sweep will: {$e->getMessage()}");
        }
    }

    /**
     * The server's name follows the VPS (#5338). Never in the way of a start: errors are kept
     * on the run and alerted by Dns itself, anything unexpected is only logged.
     */
    private function pointDns(ServerRun $run): void
    {
        try {
            $this->dns->point($run);
        } catch (Throwable $e) {
            Log::warning("Run {$run->id}: DNS not updated: {$e->getMessage()}");
        }
    }

    /**
     * Delete the run's key at the provider and wipe the private half. When the provider can't
     * be reached the key is still wiped here: the public half left at the provider is matched
     * by no active run, so the next sweep deletes it.
     */
    private function releaseKey(ServerRun $run, ?int $providerKeyId): void
    {
        if ($providerKeyId !== null) {
            try {
                $this->cloud->deleteSshKey($providerKeyId);
            } catch (CloudException $e) {
                Log::warning("Couldn't delete SSH key {$providerKeyId} of run {$run->id}; the sweep will: {$e->getMessage()}");
            }
        }

        if ($run->ssh_private_key !== null || $run->provider_ssh_key_id !== null) {
            $run->ssh_private_key = null;
            $run->provider_ssh_key_id = null;
            $run->save();
        }
    }
}
