<?php

namespace App\Servers;

use Illuminate\Contracts\Cache\Repository;

/**
 * Stands in for TcpMinecraftProbe in tests and E2E. Every host answers unless a test
 * says otherwise with `refuse()`; state lives in the cache like FakeCloudProvider's.
 */
class FakeMinecraftProbe implements MinecraftProbe
{
    private const KEY = 'fake-probe:refusing';

    public function __construct(private readonly Repository $cache) {}

    public function acceptsConnections(string $host, int $port): bool
    {
        return $this->cache->get(self::KEY) !== true;
    }

    public function refuse(bool $refuse = true): void
    {
        $this->cache->forever(self::KEY, $refuse);
    }
}
