<?php

namespace App\Servers;

/**
 * Asks a Minecraft server how it's doing. TcpMinecraftProbe talks to the real port;
 * FakeMinecraftProbe stands in for it in tests and E2E (there is no real VPS).
 */
interface MinecraftProbe
{
    /** Whether the port takes a TCP connection yet (used while starting). */
    public function acceptsConnections(string $host, int $port): bool;

    /**
     * Players online according to the server-list ping, or null when the server doesn't
     * answer it (down, crashed, or still starting).
     */
    public function playersOnline(string $host, int $port): ?int;
}
