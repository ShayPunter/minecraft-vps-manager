<?php

namespace App\Servers;

use App\Cloud\CloudException;
use App\Cloud\CloudProvider;
use App\Cloud\ServerSpec;
use App\Dns\Dns;
use App\Enums\RunStatus;
use App\Jobs\AdvanceActiveRuns;
use App\Jobs\PrepareServerRun;
use App\Jobs\ProvisionServerRun;
use App\Jobs\StopServerRun;
use App\Minecraft\Modrinth\ModrinthException;
use App\Minecraft\Modrinth\ModrinthSource;
use App\Minecraft\Packs\PackPlan;
use App\Minecraft\Software;
use App\Minecraft\VersionSource;
use App\Minecraft\VersionSourceException;
use App\Models\Server;
use App\Models\ServerRun;
use App\Models\ServerRunLog;
use App\Models\User;
use App\WorldStorage\Archives;
use App\WorldStorage\S3Transfers;
use App\WorldStorage\S3Upload;
use App\WorldStorage\StorageSettings;
use App\WorldStorage\StorageUnavailable;
use App\WorldStorage\WorldStorage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly S3Transfers $s3,
        private readonly RunLog $log,
        private readonly VersionSource $versions,
        private readonly ModrinthSource $modrinth,
    ) {}

    /**
     * $byJoin: a player joined the name of a server with Wake on join (the waker, #5818). The
     * admin turned that on for this server, so the guest rules don't apply; the cap does.
     *
     * @throws StartRefused
     */
    public function start(Server $server, ?User $user, ?string $ip, bool $byJoin = false): ServerRun
    {
        // Without world storage a stop couldn't save the world (#5288): don't start at all.
        try {
            $this->storage->settings();
        } catch (StorageUnavailable $e) {
            Log::warning("Start of {$server->slug} refused: {$e->getMessage()}");

            throw StartRefused::noWorldStorage();
        }

        try {
            $run = Cache::lock('mvm:start', 15)->block(5, fn (): ServerRun => DB::transaction(function () use ($server, $user, $ip, $byJoin): ServerRun {
                if ($server->activeRun()->exists()) {
                    throw StartRefused::alreadyRunning($server->name);
                }

                $cap = (int) config('minecraft.max_running');

                $active = ServerRun::query()->active()->count();

                if ($active >= $cap) {
                    throw StartRefused::capReached($cap);
                }

                // Guests (public card, share link) have their own budget and leave a slot free (#5526).
                // A join on a Wake on join server doesn't (#5818).
                if ($user === null && ! $byJoin) {
                    GuestStarts::check($server, $active, $cap);
                }

                $run = new ServerRun;
                $run->server_id = $server->id;
                $run->active_server_id = $server->id;
                $run->started_by = $user?->id;
                $run->started_from_ip = $ip;
                $run->started_by_join = $byJoin;
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
        // And the VPS's own host key (#5527): cloud-init installs it, every login checks it.
        // Only the public half is kept; the private one goes to the VPS in its user data.
        $hostKey = SshKeyPair::generate("host-{$name}");
        $run->ssh_host_key = SshRemoteShell::keyBlob($hostKey->publicKey);
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
                userData: CloudInit::for($server, $hostKey),
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
     * For a page view (home, dashboard, share page): queue AdvanceActiveRuns when a booting or
     * starting run is due a check (#5752). The request itself never waits on Hetzner,
     * Cloudflare, the Minecraft port or SSH; the page's next poll shows what the job found.
     */
    public function advanceSoon(): void
    {
        if ($this->dueRuns()->exists()) {
            AdvanceActiveRuns::dispatch();
        }
    }

    /**
     * Move every booting/starting run on, at most once per check interval each.
     * Runs in AdvanceActiveRuns (queued by page views, so a watched start feels quick) and
     * every minute in servers:watch ($force: whatever the interval).
     *
     * One advance per run at a time (#5752): each run is locked while it's checked, and
     * (without $force) claimed by moving checked_at on in one UPDATE that only matches while
     * it's still due, so two workers that read the same due run make one set of provider calls.
     */
    public function advanceActive(bool $force = false): void
    {
        $interval = (int) config('minecraft.check_interval');

        $this->dueRuns($force)
            ->with('server')
            ->get()
            ->each(function (ServerRun $run) use ($force, $interval): void {
                // Longer than the worst case of one advance (Hetzner 30 s, Cloudflare 10 s,
                // the probe and the SSH restart check 20 s), so it can't lapse mid-check.
                $lock = Cache::lock("mvm:advance:{$run->id}", 180);

                if (! $lock->get()) {
                    return;
                }

                try {
                    // What the database says now: another check may have moved it on, or a
                    // stop may have begun, since the list was read.
                    $run->refresh();

                    if (! in_array($run->status, [RunStatus::Booting, RunStatus::Starting], true)
                        || (! $force && ! $this->claim($run, $interval))) {
                        return;
                    }

                    $this->advance($run);
                } catch (CloudException $e) {
                    // A provider hiccup isn't a failed start: try again next time.
                    Log::warning("Couldn't check run {$run->id}: {$e->getMessage()}");
                    $run->checked_at = now();
                    $run->save();
                } finally {
                    $lock->release();
                }
            });
    }

    /**
     * Booting/starting runs, only those due a check unless $force.
     *
     * @return Builder<ServerRun>
     */
    private function dueRuns(bool $force = false): Builder
    {
        $interval = (int) config('minecraft.check_interval');

        return ServerRun::query()
            ->active()
            ->whereIn('status', [RunStatus::Booting, RunStatus::Starting])
            ->when(! $force, fn ($query) => $query->where(fn ($q) => $q
                ->whereNull('checked_at')
                ->orWhere('checked_at', '<=', now()->subSeconds($interval))));
    }

    /**
     * Take this check for ourselves: true when this UPDATE moved checked_at on, false when
     * another check got there first (it's no longer due).
     */
    private function claim(ServerRun $run, int $interval): bool
    {
        $now = now();

        $claimed = ServerRun::query()
            ->whereKey($run->id)
            ->where(fn ($q) => $q
                ->whereNull('checked_at')
                ->orWhere('checked_at', '<=', $now->copy()->subSeconds($interval)))
            ->update(['checked_at' => $now]) === 1;

        if ($claimed) {
            $run->checked_at = $now;
            $run->syncOriginalAttribute('checked_at');
        }

        return $claimed;
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

        // Minecraft keeps crashing and systemd keeps restarting it (#5376): no point waiting
        // for the boot timeout, every minute of it is paid for. (A starting run gets here only
        // once it's set up: the block above returns until then.)
        if ($run->status === RunStatus::Starting) {
            $restarts = $this->restartsSoFar($run);
            $limit = (int) config('minecraft.crash_loop_restarts');

            if ($restarts >= $limit) {
                $this->giveUpOnCrashLoop($run, $restarts);
            }
        }
    }

    /**
     * How often systemd has restarted Minecraft on this run's VPS: from the log the VPS sends
     * (systemd's "restart counter is at N"), or, when no Minecraft lines have arrived a while
     * after setup (the shipper isn't running), straight from systemd over SSH, once a minute.
     */
    private function restartsSoFar(ServerRun $run): int
    {
        $line = ServerRunLog::query()
            ->where('server_run_id', $run->id)
            ->where('source', 'minecraft')
            ->where('line', 'like', '%Scheduled restart job, restart counter is at %')
            ->orderByDesc('id')
            ->value('line');

        if (is_string($line) && preg_match('/restart counter is at (\d+)/', $line, $m) === 1) {
            return (int) $m[1];
        }

        // Only called once prepared_at is set (advance()).
        $quiet = $run->prepared_at?->lte(now()->subSeconds(90)) === true
            && ! ServerRunLog::query()->where('server_run_id', $run->id)->where('source', 'minecraft')->exists();

        if (! $quiet || $run->ipv4 === null || ! Cache::add("mvm:restart-check:{$run->id}", 1, 60)) {
            return 0;
        }

        try {
            $result = $this->shell->run($run->ipv4, CloudInit::restartsCommand($run->server), 20, $run->ssh_private_key, $run->ssh_host_key);
        } catch (RemoteShellException $e) {
            Log::info("Run {$run->id}: couldn't ask systemd how often Minecraft restarted: {$e->getMessage()}");

            return 0;
        }

        return $result->succeeded() && preg_match('/^\s*(\d+)\s*$/', $result->output, $m) === 1 ? (int) $m[1] : 0;
    }

    private function giveUpOnCrashLoop(ServerRun $run, int $restarts): void
    {
        $error = "Minecraft crashed and was restarted {$restarts} times while starting, so the VPS was deleted instead of waiting for the boot timeout.";

        try {
            $this->abandon($run, $error, 'crash_loop');
        } catch (CloudException $e) {
            // The boot timeout tries again later.
            Log::warning("Run {$run->id}: couldn't delete the VPS of a crash-looping start: {$e->getMessage()}");

            return;
        }

        $this->alerter->send("{$run->server->name} didn't start", (string) $run->error);
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
                $result = $this->shell->run($run->ipv4, CloudInit::stopCommand($server), 360, $run->ssh_private_key, $run->ssh_host_key);
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
    public function abandon(ServerRun $run, string $error, string $reason = 'boot_timeout'): void
    {
        if ($run->provider_server_id !== null) {
            // The VPS (and Minecraft's log on it) is about to go: keep its last lines (#5367).
            $this->pullFallbackLog($run);
            $this->cloud->deleteServer($run->provider_server_id);
        }

        $cause = $this->log->diagnose($run);

        $run->stop_reason = $reason;
        $this->fail($run, $cause !== null ? "{$error} Likely cause: {$cause}" : $error);
    }

    /**
     * The last journal lines and the setup output, over SSH, before the VPS is deleted. Only
     * a best effort: a VPS that never let the app in has nothing to give.
     */
    private function pullFallbackLog(ServerRun $run): void
    {
        if ($run->ipv4 === null || $run->ended_at !== null) {
            return;
        }

        try {
            $result = $this->shell->run($run->ipv4, RunLog::fallbackCommand($run->server), 25, $run->ssh_private_key, $run->ssh_host_key);
            $this->log->append($run, 'fallback', $result->output);
        } catch (Throwable $e) {
            Log::info("Run {$run->id}: no log pulled before deleting the VPS: {$e->getMessage()}");
        }
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

        $downloadUrl = null;

        if ($archive !== null && $storage !== null && $storage->isS3()) {
            try {
                // S3 (#5347): a link to this one archive, valid a little longer than the job.
                $downloadUrl = $this->s3->downloadUrl($storage, $archive->path, (int) config('minecraft.prepare_timeout') + 600);
            } catch (Throwable $e) {
                Log::info("Run {$run->id}: couldn't sign the world download link, trying again: {$e->getMessage()}");
                $this->retryPrepare($run);

                return;
            }
        }

        // No saved world yet and a Vanilla/Paper/Fabric server (#5351): install it. The jars
        // are looked up now, so Paper and the Fabric loader get their newest stable build.
        // After the first stop the whole folder is saved, so later starts never reinstall.
        $install = null;

        if ($archive === null && $server->software === Software::PackUrl && $server->pack_url !== null) {
            // A server-pack zip (#5352): its URL was checked when it was saved.
            $install = PackPlan::forUrl($server->pack_url);
        } elseif ($archive === null && $server->software === Software::Modrinth && $server->modrinth_version_id !== null) {
            // A Modrinth pack (#5352): its .mrpack is read now, so the index is the current one.
            try {
                $install = $this->modrinth->plan($server->modrinth_version_id, (string) ($server->modrinth_title ?? $server->name));
            } catch (ModrinthException $e) {
                if ($e->permanent) {
                    $this->giveUp($run, "The Modrinth pack can't be installed: {$e->getMessage()}");

                    return;
                }

                Log::info("Run {$run->id}: couldn't read the Modrinth pack, trying again: {$e->getMessage()}");
                $this->log->append($run, 'prepare', "Couldn't read the Modrinth pack yet, trying again: {$e->getMessage()}");
                $this->retryPrepare($run);

                return;
            }
        } elseif ($archive === null && in_array($server->software, Software::versioned(), true) && $server->minecraft_version !== null) {
            try {
                $install = $this->versions->plan($server->software, $server->minecraft_version);
            } catch (VersionSourceException $e) {
                Log::info("Run {$run->id}: couldn't look up the install, trying again: {$e->getMessage()}");
                $this->log->append($run, 'prepare', "Couldn't look up the {$server->software->label()} {$server->minecraft_version} download yet, trying again: {$e->getMessage()}");
                $this->retryPrepare($run);

                return;
            }
        }

        // The log shipper (#5367): a fresh token on every attempt, only its hash kept.
        $shipper = RunLog::shipperFiles($server, $run, $this->log->issueToken($run));

        try {
            $this->shell->putFiles($run->ipv4, WorldSync::prepareFiles($server, $archive, $storage, $downloadUrl, $install) + $shipper, $run->ssh_private_key, $run->ssh_host_key);
            $timeout = (int) config($install instanceof PackPlan ? 'minecraft.pack_prepare_timeout' : 'minecraft.prepare_timeout');
            $result = $this->shell->run($run->ipv4, WorldSync::PREPARE_COMMAND, $timeout, $run->ssh_private_key, $run->ssh_host_key);
        } catch (RemoteShellException $e) {
            Log::info("Run {$run->id}: VPS not ready for the world yet, trying again: {$e->getMessage()}");
            $this->retryPrepare($run);

            return;
        }

        if ($result->exitCode === WorldSync::EXIT_BUSY) {
            $this->retryPrepare($run);

            return;
        }

        $this->log->append($run, 'prepare', $result->output);

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
        $upload = null;

        if ($storage->isS3()) {
            try {
                // S3 (#5347): the app starts the multipart upload with its own keys; the VPS
                // only gets links for the parts, the completion and the read-back.
                $upload = $this->s3->startUpload(
                    $storage,
                    $path,
                    (int) config('minecraft.save_timeout') + 600,
                    (int) config('world_storage.s3_part_mb') * 1024 * 1024,
                    $this->s3MaxParts(),
                );
            } catch (Throwable $e) {
                $this->stopFailed($run, "Minecraft stopped, but world storage wouldn't start the upload: {$e->getMessage()}");

                return false;
            }
        }

        try {
            $this->shell->putFiles($host, WorldSync::saveFiles($server, $storage, $upload), $run->ssh_private_key, $run->ssh_host_key);
            $result = $this->shell->run($host, WorldSync::saveCommand($server, $path), (int) config('minecraft.save_timeout'), $run->ssh_private_key, $run->ssh_host_key);
        } catch (RemoteShellException $e) {
            $this->abortUpload($run, $storage, $upload);
            $this->stopFailed($run, "Minecraft stopped, but uploading the world failed: {$e->getMessage()}");

            return false;
        }

        if (! $result->succeeded()) {
            $this->abortUpload($run, $storage, $upload);
            $output = mb_substr(trim($result->output), -500);
            $this->stopFailed($run, "Minecraft stopped, but uploading the world failed (exit {$result->exitCode}".($output !== '' ? ": {$output}" : '').').');

            return false;
        }

        try {
            $saved = WorldSync::parseSaveOutput($result->output);
        } catch (InvalidArgumentException $e) {
            $this->abortUpload($run, $storage, $upload);
            $this->stopFailed($run, "Minecraft stopped, but the upload didn't finish: {$e->getMessage()}");

            return false;
        }

        if ($saved === null) {
            $this->abortUpload($run, $storage, $upload);
            Log::info("Run {$run->id}: the server folder was empty, nothing to save.");

            return true;
        }

        try {
            $stored = $this->storage->disk($storage)->size($path);
        } catch (Throwable $e) {
            $this->abortUpload($run, $storage, $upload);
            $this->stopFailed($run, "The world was uploaded, but the app couldn't check it on world storage: {$e->getMessage()}");

            return false;
        }

        if ($stored !== $saved['size']) {
            $this->abortUpload($run, $storage, $upload);
            $this->stopFailed($run, "World storage has {$stored} bytes of the {$saved['size']} uploaded.");

            return false;
        }

        $archive = $this->archives->record($server, $path, $saved['size'], $saved['sha256'], 'save', $run, files: $saved['files']);
        $run->saved_world_archive_id = $archive->id;
        $run->save();

        Log::info('World saved', ['server' => $server->slug, 'run' => $run->id, 'archive' => $archive->id, 'size' => $saved['size']]);

        return true;
    }

    /**
     * Enough part links for a world of world_storage.s3_max_world_gb (S3 allows 10,000).
     */
    private function s3MaxParts(): int
    {
        $partMb = (int) config('world_storage.s3_part_mb');

        return min(10000, (int) ceil((int) config('world_storage.s3_max_world_gb') * 1024 / $partMb));
    }

    /**
     * A save that didn't count: drop its unfinished parts (or the object, if it was finished).
     */
    private function abortUpload(ServerRun $run, StorageSettings $storage, ?S3Upload $upload): void
    {
        if ($upload === null) {
            return;
        }

        $problem = $this->s3->abort($storage, $upload);

        if ($problem !== null) {
            Log::warning("Run {$run->id}: couldn't clean up the S3 upload of {$upload->path}: {$problem}");
        }
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
