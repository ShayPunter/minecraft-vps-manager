<?php

namespace App\Servers;

/**
 * Tells whether a Minecraft server accepts connections yet. TcpMinecraftProbe opens the
 * port; FakeMinecraftProbe stands in for it in tests and E2E (there is no real VPS).
 */
interface MinecraftProbe
{
    public function acceptsConnections(string $host, int $port): bool;
}
