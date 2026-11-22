<?php

namespace App\Cloud;

use Illuminate\Contracts\Cache\Repository;

/**
 * Stands in for Hetzner in tests and E2E. State lives in the cache, so it survives
 * between the separate PHP processes of an `artisan serve` E2E run (CACHE_STORE=database)
 * and resets per test (array store). New servers start as `initializing`; tests and
 * specs move them on with `setStatus()`.
 */
class FakeCloudProvider implements CloudProvider
{
    private const KEY = 'fake-cloud:servers';

    /**
     * @param  bool  $autoBoot  report an `initializing` server as `running` the next time
     *                          it's looked up (E2E, where no test can call setStatus())
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly bool $autoBoot = false,
    ) {}

    public function createServer(ServerSpec $spec): CloudServer
    {
        if ($spec->sshKeyIds === []) {
            throw new CloudException('not_configured', 'A VPS needs at least one SSH key.');
        }

        $keys = $this->keys();

        foreach ($spec->sshKeyIds as $keyId) {
            if (! isset($keys[$keyId])) {
                throw new CloudException('invalid_input', "SSH key {$keyId} not found.", 422);
            }
        }

        $servers = $this->all();
        $id = $servers === [] ? 1001 : max(array_keys($servers)) + 1;

        $servers[$id] = [
            'name' => $spec->name,
            'status' => 'initializing',
            'ipv4' => '203.0.113.'.($id % 250 + 1),
            'labels' => $spec->allLabels(),
            'server_type' => $spec->serverType,
            'location' => $spec->location,
            'user_data' => $spec->userData,
            'ssh_keys' => $spec->sshKeyIds,
        ];
        $this->save($servers);

        return $this->toServer($id, $servers[$id]);
    }

    public function createSshKey(string $name, string $publicKey, array $labels = []): CloudSshKey
    {
        $keys = $this->keys();

        foreach ($keys as $key) {
            if ($key['name'] === $name) {
                throw new CloudException('uniqueness_error', 'SSH key with the same name already exists', 409);
            }
        }

        $id = $keys === [] ? 501 : max(array_keys($keys)) + 1;
        $keys[$id] = [
            'name' => $name,
            'public_key' => trim($publicKey),
            'labels' => [...$labels, self::MANAGED_LABEL => self::MANAGED_VALUE],
        ];
        $this->saveKeys($keys);

        return new CloudSshKey($id, $name, $keys[$id]['labels']);
    }

    public function deleteSshKey(int $id): void
    {
        if ($this->cache->get(self::KEY.':fail-key-deletes') === true) {
            throw new CloudException('connection_failed', 'Could not reach Hetzner: fake outage');
        }

        $keys = $this->keys();

        if (! isset($keys[$id])) {
            return;
        }

        if (($keys[$id]['labels'][self::MANAGED_LABEL] ?? null) !== self::MANAGED_VALUE) {
            throw new CloudException('not_managed', "SSH key {$id} wasn't created by this app; refusing to delete it.");
        }

        unset($keys[$id]);
        $this->saveKeys($keys);
    }

    public function managedSshKeys(): array
    {
        $managed = [];

        foreach ($this->keys() as $id => $key) {
            $cloudKey = new CloudSshKey($id, $key['name'], $key['labels']);

            if ($cloudKey->isManaged()) {
                $managed[] = $cloudKey;
            }
        }

        return $managed;
    }

    /**
     * Test helper: a key someone added by hand in the same project (no managed-by label).
     */
    public function addUnmanagedSshKey(string $name): CloudSshKey
    {
        $keys = $this->keys();
        $id = $keys === [] ? 501 : max(array_keys($keys)) + 1;
        $keys[$id] = ['name' => $name, 'public_key' => 'ssh-ed25519 AAAA shay', 'labels' => []];
        $this->saveKeys($keys);

        return new CloudSshKey($id, $name, []);
    }

    /**
     * Test helper: the public key registered under this id, or null when there's none.
     */
    public function sshPublicKey(int $id): ?string
    {
        return $this->keys()[$id]['public_key'] ?? null;
    }

    /**
     * Test helper: make the next deleteSshKey() calls fail (e.g. Hetzner is down).
     */
    public function failSshKeyDeletes(bool $fail = true): void
    {
        $this->cache->forever(self::KEY.':fail-key-deletes', $fail);
    }

    public function findServer(int $id): ?CloudServer
    {
        $server = $this->all()[$id] ?? null;

        if ($server !== null && $this->autoBoot && $server['status'] === 'initializing') {
            $this->setStatus($id, 'running');
            $server['status'] = 'running';
        }

        return $server === null ? null : $this->toServer($id, $server);
    }

    public function deleteServer(int $id): void
    {
        $servers = $this->all();

        if (! isset($servers[$id])) {
            return;
        }

        if (! $this->toServer($id, $servers[$id])->isManaged()) {
            throw new CloudException('not_managed', "Server {$id} wasn't created by this app; refusing to delete it.");
        }

        unset($servers[$id]);
        $this->save($servers);
    }

    public function managedServers(): array
    {
        $servers = [];

        foreach ($this->all() as $id => $server) {
            $cloudServer = $this->toServer($id, $server);

            if ($cloudServer->isManaged()) {
                $servers[] = $cloudServer;
            }
        }

        return $servers;
    }

    /**
     * Made-up types and prices for tests; the real list comes from Hetzner.
     */
    public function serverTypes(): array
    {
        $everywhere = fn (string $monthly, string $hourly, array $locations = ['fsn1', 'nbg1', 'hel1']): array => array_fill_keys($locations, ['monthly' => $monthly, 'hourly' => $hourly]);

        return [
            new ServerType('cx43', 'CX43', 8, 16, 160, 'shared', 'x86', 'Shared vCPU', $everywhere('11.9900', '0.0192')),
            new ServerType('cx33', 'CX33', 4, 8, 80, 'shared', 'x86', 'Shared vCPU', $everywhere('6.4900', '0.0104')),
            new ServerType('cax31', 'CAX31', 8, 16, 160, 'shared', 'arm', 'Shared vCPU', $everywhere('12.4900', '0.0200', ['fsn1', 'nbg1'])),
            new ServerType('ccx23', 'CCX23 Dedicated', 4, 16, 160, 'dedicated', 'x86', 'Dedicated vCPU', $everywhere('31.4900', '0.0505', ['fsn1', 'nbg1'])),
            // Retired everywhere, as CPX11/CPX21 are at Hetzner: must not be offered.
            new ServerType('cpx21', 'CPX21', 3, 4, 80, 'shared', 'x86', 'Shared vCPU', [], ['fsn1', 'nbg1', 'hel1']),
            // Retired in Germany only.
            new ServerType('cx53', 'CX53', 16, 32, 320, 'shared', 'x86', 'Shared vCPU', $everywhere('22.4900', '0.0360', ['hel1']), ['fsn1', 'nbg1']),
        ];
    }

    public function locations(): array
    {
        return [
            new Location('fsn1', 'Falkenstein DC Park 1', 'Falkenstein', 'DE'),
            new Location('nbg1', 'Nuremberg DC Park 1', 'Nuremberg', 'DE'),
            new Location('hel1', 'Helsinki DC Park 1', 'Helsinki', 'FI'),
        ];
    }

    /**
     * Test helper: move a fake server to another status (e.g. `running`).
     */
    public function setStatus(int $id, string $status): void
    {
        $servers = $this->all();

        if (isset($servers[$id])) {
            $servers[$id]['status'] = $status;
            $this->save($servers);
        }
    }

    /**
     * Test helper: a server someone made by hand in the same project (no managed-by label).
     */
    public function addUnmanagedServer(string $name): CloudServer
    {
        $servers = $this->all();
        $id = $servers === [] ? 1001 : max(array_keys($servers)) + 1;
        $servers[$id] = ['name' => $name, 'status' => 'running', 'ipv4' => '198.51.100.7', 'labels' => []];
        $this->save($servers);

        return $this->toServer($id, $servers[$id]);
    }

    /**
     * Test helper: what the last created server was given.
     *
     * @return array<string, mixed>|null
     */
    public function rawServer(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    public function reset(): void
    {
        $this->cache->forget(self::KEY);
        $this->cache->forget(self::KEY.':ssh-keys');
        $this->cache->forget(self::KEY.':fail-key-deletes');
    }

    /**
     * @return array<int, array{name: string, public_key: string, labels: array<string, string>}>
     */
    private function keys(): array
    {
        $keys = $this->cache->get(self::KEY.':ssh-keys', []);

        /** @var array<int, array{name: string, public_key: string, labels: array<string, string>}> */
        return is_array($keys) ? $keys : [];
    }

    /**
     * @param  array<int, array{name: string, public_key: string, labels: array<string, string>}>  $keys
     */
    private function saveKeys(array $keys): void
    {
        $this->cache->forever(self::KEY.':ssh-keys', $keys);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(): array
    {
        $servers = $this->cache->get(self::KEY, []);

        return is_array($servers) ? $servers : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $servers
     */
    private function save(array $servers): void
    {
        $this->cache->forever(self::KEY, $servers);
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function toServer(int $id, array $server): CloudServer
    {
        /** @var array<string, string> $labels */
        $labels = is_array($server['labels'] ?? null) ? $server['labels'] : [];

        return new CloudServer(
            id: $id,
            name: (string) $server['name'],
            status: (string) $server['status'],
            ipv4: isset($server['ipv4']) ? (string) $server['ipv4'] : null,
            labels: $labels,
        );
    }
}
