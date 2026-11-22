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
        ];
        $this->save($servers);

        return $this->toServer($id, $servers[$id]);
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
        return [
            new ServerType('cx33', 'CX33', 4, 8, 80, 'shared', 'x86', false, ['fsn1' => '6.4900', 'nbg1' => '6.4900', 'hel1' => '6.4900']),
            new ServerType('cx43', 'CX43', 8, 16, 160, 'shared', 'x86', false, ['fsn1' => '11.9900', 'nbg1' => '11.9900', 'hel1' => '11.9900']),
            new ServerType('ccx23', 'CCX23 Dedicated', 4, 16, 160, 'dedicated', 'x86', false, ['fsn1' => '31.4900', 'nbg1' => '31.4900']),
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
