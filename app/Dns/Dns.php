<?php

namespace App\Dns;

use App\Models\DnsSetting;
use App\Models\Server;
use App\Models\ServerRun;
use App\Servers\Alerter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A name per server (#5338): while a run's VPS exists, <subdomain>.<domain> is an A record
 * pointing at it. The record is made once the VPS has its IP and deleted when the run ends
 * (Hetzner hands IPs out again, so a stale record could send players to a stranger's machine).
 * Nothing here ever stops a start or a stop: an error is saved on the run, alerted once, and
 * players can still use the IP.
 */
class Dns
{
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
     * The run is over (its VPS is gone): delete its record. If Cloudflare can't be reached the
     * record is forgotten here anyway; the sweep finds it by its comment and deletes it.
     */
    public function release(ServerRun $run): void
    {
        if ($run->dns_record_id === null) {
            return;
        }

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
