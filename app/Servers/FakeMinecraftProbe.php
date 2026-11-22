<?php

namespace App\Servers;

use Illuminate\Contracts\Cache\Repository;

/**
 * Stands in for TcpMinecraftProbe in tests and E2E. Every host answers, with nobody on,
 * unless a test says otherwise with `refuse()` or `setPlayers()`; state lives in the cache
 * like FakeCloudProvider's.
 */
class FakeMinecraftProbe implements MinecraftProbe
{
    private const KEY = 'fake-probe:refusing';

    private const PLAYERS_KEY = 'fake-probe:players';

    public function __construct(private readonly Repository $cache) {}

    public function acceptsConnections(string $host, int $port): bool
    {
        return $this->cache->get(self::KEY) !== true;
    }

    public function playersOnline(string $host, int $port): ?int
    {
        if (! $this->acceptsConnections($host, $port)) {
            return null;
        }

        $players = $this->cache->get(self::PLAYERS_KEY, 0);

        return is_int($players) ? $players : 0;
    }

    public function refuse(bool $refuse = true): void
    {
        $this->cache->forever(self::KEY, $refuse);
    }

    public function setPlayers(int $players): void
    {
        $this->cache->forever(self::PLAYERS_KEY, $players);
    }
}
