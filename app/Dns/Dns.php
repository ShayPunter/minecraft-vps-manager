<?php

namespace App\Dns;

use App\Models\DnsSetting;
use App\Models\Server;
use App\Models\ServerRun;
use App\Servers\Alerter;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A name per server (#5338): while a run's VPS exists, <subdomain>.<domain> is an A record
 * pointing at it. The record is made once the VPS has its IP and deleted when the run ends
 * (Hetzner hands IPs out again, so a stale record could send players to a stranger's machine).
 * Nothing here ever stops a start or a stop: an error is saved on the run, alerted once, and
 * players can still use the IP.
 *
 * Wake on join (#5620): while a server with `wake_on_join` has no run whose VPS holds its
 * name, the name is "parked": an A record at the waker's address (Admin → DNS), labelled
 * `mvm-waker-server=<id>`. point() replaces it with the VPS's record, release() parks it
 * again, and syncParked() (the sweep, and after saving a server or the DNS settings) fixes
 * whatever is left over. A lock per server keeps point() and a park from racing.
 */
class Dns
{
    /** Seconds a point or park may hold a server's DNS lock (each Cloudflare call gives up after 10). */
    private const LOCK_SECONDS = 60;

    public function __construct(
        private readonly DnsProvider $provider,
        private readonly Alerter $alerter,
    ) {}

    public function saved(): ?DnsSetting
    {
        return DnsSetting::query()->first();
    }

    /**
     * The zone from Admin → DNS, or null when it isn't set up (or APP_KEY changed since).
     */
    public function zone(): ?DnsZone
    {
        $row = $this->saved();

        if ($row === null) {
            return null;
        }

        try {
            // The encrypted cast decrypts on read, and throws when APP_KEY changed since.
            $token = $row->getAttribute('api_token');
        } catch (DecryptException) {
            return null;
        }

        return is_string($token) ? new DnsZone($row->domain, $row->zone_id, $token) : null;
    }

    /**
     * Why DNS can't be used right now, for the admin pages (null: it can).
     */
    public function problem(): ?string
    {
        $row = $this->saved();

        if ($row === null) {
            return "DNS isn't set up: servers are reached by their IP.";
        }

        return $this->zone() === null
            ? "The saved Cloudflare token can't be read (APP_KEY changed since it was saved). Enter it again."
            : null;
    }

    public function nameFor(Server $server, ?DnsZone $zone = null): ?string
    {
        $zone ??= $this->zone();

        if ($server->subdomain === null || $zone === null) {
            return null;
        }

        return $server->subdomain.'.'.$zone->domain;
    }

    /**
     * Make and delete a TXT record: proves the token may edit this zone's DNS and that the
     * zone id belongs to the domain (Cloudflare appends the zone's own name to a name outside it).
     *
     * @return string|null why it failed (safe to show), or null when it worked
     */
    public function test(DnsZone $zone): ?string
    {
        $name = '_mvm-test-'.Str::lower(Str::random(8)).'.'.$zone->domain;

        try {
            $record = $this->provider->createRecord($zone, 'TXT', $name, '"minecraft-vps-manager connection test"', DnsRecord::MANAGED.' test');
        } catch (DnsException $e) {
            return "Couldn't make a test record in that zone: {$e->getMessage()}";
        }

        try {
            $this->provider->deleteRecord($zone, $record->id);
        } catch (DnsException $e) {
            Log::warning("DNS test record {$record->id} wasn't deleted; the sweep will: {$e->getMessage()}");
        }

        if (strtolower($record->name) !== strtolower($name)) {
            return "That zone id doesn't belong to {$zone->domain}: Cloudflare put the test record at {$record->name}.";
        }

        return null;
    }

    /**
     * Point the server's name at the run's VPS. Does nothing when the server has no
     * subdomain, the VPS has no IP yet, or the run already has its record.
     */
    public function point(ServerRun $run): void
    {
        $server = $run->server;

        if ($server->subdomain === null || $run->ipv4 === null || $run->dns_record_id !== null) {
            return;
        }

        try {
            $this->locked($server, fn () => $this->pointNow($run->refresh()));
        } catch (LockTimeoutException) {
            // A park is still busy with the name: the next check tries again.
            Log::info("Run {$run->id}: DNS busy for {$server->slug}, trying again on the next check.");
        }
    }

    private function pointNow(ServerRun $run): void
    {
        $server = $run->server;

        if ($run->dns_record_id !== null || ! $run->status->isActive()) {
            return;
        }

        $zone = $this->zone();

        if ($zone === null) {
            $this->failed($run, (string) $this->problem());

            return;
        }

        $name = (string) $this->nameFor($server, $zone);

        try {
            foreach ($this->provider->recordsNamed($zone, $name) as $existing) {
                if (! $existing->isManaged()) {
                    $this->failed($run, "{$name} already has a {$existing->type} record that wasn't made by this app. "
                        .'Delete it in Cloudflare, or give the server another subdomain.');

                    return;
                }

                $owner = $existing->runId() !== null ? ServerRun::query()->active()->whereKey($existing->runId())->first() : null;

                if ($owner !== null && $owner->id !== $run->id) {
                    $this->failed($run, "{$name} still points at {$owner->server->name}, which is running.");

                    return;
                }

                // Left behind by an earlier run (or an earlier try of this one): replace it.
                $this->provider->deleteRecord($zone, $existing->id);
            }

            $record = $this->provider->createRecord($zone, 'A', $name, $run->ipv4, DnsRecord::commentForRun($run->id));
        } catch (DnsException $e) {
            $this->failed($run, "Couldn't set {$name}: {$e->getMessage()}");

            return;
        }

        $run->dns_record_id = $record->id;
        $run->dns_zone_id = $zone->zoneId;
        $run->dns_name = $name;
        $run->dns_error = null;
        $run->save();

        Log::info('DNS record set', ['server' => $server->slug, 'run' => $run->id, 'name' => $name]);
    }

    /**
     * The run is over (its VPS is gone): delete its record, and park the name at the waker
     * again when the server wakes on join. If Cloudflare can't be reached the record is
     * forgotten here anyway; the sweep finds it by its comment and deletes it.
     */
    public function release(ServerRun $run): void
    {
        if ($run->dns_record_id !== null) {
            $this->deleteRunRecord($run);
        }

        if ($run->server->wake_on_join) {
            $this->syncParked($run->server);
        }
    }

    private function deleteRunRecord(ServerRun $run): void
    {
        $zone = $this->zone();

        if ($zone !== null) {
            try {
                $this->provider->deleteRecord($zone->withZoneId((string) $run->dns_zone_id), $run->dns_record_id);
            } catch (DnsException $e) {
                if (! $e->isNotFound()) {
                    Log::warning("Couldn't delete DNS record {$run->dns_record_id} of run {$run->id}; the sweep will: {$e->getMessage()}");
                }
            }
        } else {
            Log::warning("DNS record {$run->dns_record_id} of run {$run->id} left in place: DNS isn't set up any more.");
        }

        $run->dns_record_id = null;
        $run->save();
    }

    /**
     * Records with the app's comment that no active run owns: left behind when Cloudflare
     * couldn't be reached as a run ended, or a test record that wasn't deleted. A record
     * labelled with an active run's id is kept (it may be the one being made right now).
     * Waker records are syncParked()'s to keep or delete.
     */
    public function deleteOrphans(): void
    {
        $zone = $this->zone();

        if ($zone === null) {
            return;
        }

        try {
            $records = $this->provider->managedRecords($zone);
        } catch (DnsException $e) {
            Log::warning("DNS sweep skipped: {$e->getMessage()}");

            return;
        }

        $active = ServerRun::query()->active()->get(['id', 'dns_record_id']);
        $knownIds = $active->pluck('dns_record_id')->filter()->all();
        $activeRunIds = $active->pluck('id')->all();

        foreach ($records as $record) {
            if ($record->wakerServerId() !== null) {
                continue;
            }

            if (in_array($record->id, $knownIds, true) || ($record->runId() !== null && in_array($record->runId(), $activeRunIds, true))) {
                continue;
            }

            try {
                $this->provider->deleteRecord($zone, $record->id);
                Log::info("Deleted orphan DNS record {$record->name} ({$record->id})");
            } catch (Throwable $e) {
                Log::warning("Couldn't delete orphan DNS record {$record->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * The address `servers:waker` listens on, from Admin → DNS (#5620), or null.
     */
    public function wakerAddress(): ?string
    {
        $ip = $this->saved()?->waker_ipv4;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Whether the server's name should point at the waker now: it wakes on join, has a name,
     * there is a waker, and no active run's VPS holds the name. A run that is still being
     * created keeps the waker's record, so a ping shows "starting" until the VPS has its IP.
     */
    public function shouldPark(Server $server): bool
    {
        return $server->wake_on_join
            && $server->subdomain !== null
            && $this->wakerAddress() !== null
            && ! ServerRun::query()->active()->where('server_id', $server->id)->whereNotNull('dns_record_id')->exists();
    }

    /**
     * Bring the waker records in line with what shouldPark() says: one A record at the waker
     * for each server that should park, none for any other (wake on join turned off, the
     * subdomain or the waker's address changed, the server deleted). With a server, only its
     * records are looked at. Errors are logged; the sweep runs this again every 10 minutes.
     */
    public function syncParked(?Server $only = null): void
    {
        $zone = $this->zone();

        if ($zone === null) {
            return;
        }

        try {
            $records = $this->provider->managedRecords($zone);
        } catch (DnsException $e) {
            Log::warning("Waker DNS records not checked: {$e->getMessage()}");

            return;
        }

        $servers = $only !== null
            ? array_filter([$only->fresh()])
            : Server::query()->where('wake_on_join', true)->whereNotNull('subdomain')->get()->all();
        $waker = $this->wakerAddress();
        $wanted = [];

        foreach ($servers as $server) {
            if ($waker !== null && $this->shouldPark($server)) {
                $wanted[$server->id] = $server;
            }
        }

        $kept = [];

        foreach ($records as $record) {
            $serverId = $record->wakerServerId();

            if ($serverId === null || ($only !== null && $serverId !== $only->id)) {
                continue;
            }

            $server = $wanted[$serverId] ?? null;

            if ($server !== null && ! isset($kept[$serverId]) && $record->type === 'A' && $record->content === $waker
                && strtolower($record->name) === strtolower((string) $this->nameFor($server, $zone))) {
                $kept[$serverId] = true;

                continue;
            }

            try {
                $this->provider->deleteRecord($zone, $record->id);
                Log::info("Deleted waker DNS record {$record->name} ({$record->id})");
            } catch (DnsException $e) {
                if (! $e->isNotFound()) {
                    Log::warning("Couldn't delete waker DNS record {$record->id}: {$e->getMessage()}");
                }
            }
        }

        foreach ($wanted as $serverId => $server) {
            if (isset($kept[$serverId])) {
                continue;
            }

            try {
                $this->locked($server, fn () => $this->park($server, $zone));
            } catch (LockTimeoutException) {
                Log::info("Waker DNS record for {$server->slug} not made: the name is busy. The sweep tries again.");
            }
        }
    }

    /**
     * Point the server's name at the waker. Inside the server's DNS lock, so it checks again.
     */
    private function park(Server $server, DnsZone $zone): void
    {
        $waker = $this->wakerAddress();

        if ($waker === null || ! $this->shouldPark($server)) {
            return;
        }

        $name = (string) $this->nameFor($server, $zone);

        try {
            foreach ($this->provider->recordsNamed($zone, $name) as $existing) {
                if (! $existing->isManaged()) {
                    Log::warning("Waker DNS record for {$name} not made: it has a {$existing->type} record that wasn't made by this app.");

                    return;
                }

                $runId = $existing->runId();

                if ($runId !== null && ServerRun::query()->active()->whereKey($runId)->exists()) {
                    // A running server holds the name (its own, or one that had the name before).
                    return;
                }

                // A record from an ended run, or a waker record with an old address: replace it.
                $this->provider->deleteRecord($zone, $existing->id);
            }

            $this->provider->createRecord($zone, 'A', $name, $waker, DnsRecord::commentForWaker($server->id));
        } catch (DnsException $e) {
            Log::warning("Waker DNS record for {$name} not made; the sweep tries again: {$e->getMessage()}");

            return;
        }

        Log::info('Waker DNS record set', ['server' => $server->slug, 'name' => $name]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    private function locked(Server $server, callable $callback): mixed
    {
        return Cache::lock("mvm:dns:server:{$server->id}", self::LOCK_SECONDS)->block(15, $callback);
    }

    private function failed(ServerRun $run, string $error): void
    {
        $first = $run->dns_error === null;
        $run->dns_error = mb_substr($error, 0, 1000);
        $run->save();

        Log::warning("Run {$run->id}: {$error}");

        if ($first) {
            $this->alerter->send(
                "{$run->server->name} has no address name",
                "{$error} The server still starts; players can join with its IP until this is fixed.",
            );
        }
    }
}
