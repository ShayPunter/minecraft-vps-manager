<?php

namespace App\Servers;

/**
 * Minecraft is up once its port takes a TCP connection. (Player counts need the
 * server-list ping, which the watchdog adds.)
 */
class TcpMinecraftProbe implements MinecraftProbe
{
    public function __construct(private readonly float $timeoutSeconds = 2.0) {}

    public function acceptsConnections(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, $this->timeoutSeconds);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
