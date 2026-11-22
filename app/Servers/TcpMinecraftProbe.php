<?php

namespace App\Servers;

use RuntimeException;

/**
 * Minecraft is up once its port takes a TCP connection. Player counts come from the
 * server-list ping (the "status" handshake every Java server answers since 1.7):
 * https://minecraft.wiki/w/Java_Edition_protocol/Server_List_Ping
 */
class TcpMinecraftProbe implements MinecraftProbe
{
    /** Any version works for a status request; servers answer with their own. */
    private const PROTOCOL_VERSION = 47;

    /** Status JSON with a server icon is a few tens of KB; anything this big is not Minecraft. */
    private const MAX_RESPONSE_BYTES = 1_048_576;

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

    public function playersOnline(string $host, int $port): ?int
    {
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, $this->timeoutSeconds);

        if ($socket === false) {
            return null;
        }

        try {
            stream_set_timeout($socket, (int) ceil($this->timeoutSeconds));
            fwrite($socket, self::statusRequest($host, $port));

            return self::readPlayers($socket);
        } catch (RuntimeException) {
            return null;
        } finally {
            fclose($socket);
        }
    }

    /**
     * The handshake (next state: status) followed by the empty status request.
     */
    public static function statusRequest(string $host, int $port): string
    {
        $handshake = self::varInt(0x00)
            .self::varInt(self::PROTOCOL_VERSION)
            .self::varInt(strlen($host)).$host
            .pack('n', $port)
            .self::varInt(1);

        return self::varInt(strlen($handshake)).$handshake
            .self::varInt(1).self::varInt(0x00);
    }

    /**
     * Read the status response and return `players.online`.
     *
     * @param  resource  $stream
     *
     * @throws RuntimeException when the answer isn't a status response
     */
    public static function readPlayers($stream): int
    {
        $length = self::readVarInt($stream);
        $packetId = self::readVarInt($stream);

        if ($packetId !== 0x00) {
            throw new RuntimeException("Unexpected packet {$packetId}.");
        }

        $jsonLength = self::readVarInt($stream);

        if ($jsonLength <= 0 || $jsonLength > self::MAX_RESPONSE_BYTES || $jsonLength > $length) {
            throw new RuntimeException('Bad status length.');
        }

        $json = self::readExactly($stream, $jsonLength);
        $status = json_decode($json, true);
        $online = is_array($status) && is_array($status['players'] ?? null) ? ($status['players']['online'] ?? null) : null;

        if (! is_int($online) || $online < 0) {
            throw new RuntimeException('No player count in the status.');
        }

        return $online;
    }

    public static function varInt(int $value): string
    {
        $value &= 0xFFFFFFFF;
        $bytes = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $bytes .= chr($value !== 0 ? $byte | 0x80 : $byte);
        } while ($value !== 0);

        return $bytes;
    }

    /**
     * @param  resource  $stream
     */
    private static function readVarInt($stream): int
    {
        $value = 0;

        for ($i = 0; $i < 5; $i++) {
            $byte = ord(self::readExactly($stream, 1));
            $value |= ($byte & 0x7F) << (7 * $i);

            if (($byte & 0x80) === 0) {
                return $value;
            }
        }

        throw new RuntimeException('VarInt too long.');
    }

    /**
     * @param  resource  $stream
     */
    private static function readExactly($stream, int $length): string
    {
        $data = '';

        while (($missing = $length - strlen($data)) > 0) {
            $chunk = fread($stream, $missing);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('The server closed the connection or timed out.');
            }

            $data .= $chunk;
        }

        return $data;
    }
}
