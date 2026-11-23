<?php

namespace App\Waker;

/**
 * The little of Minecraft's Java protocol the waker needs (#5620), on byte strings:
 * https://minecraft.wiki/w/Java_Edition_protocol/Packets (handshake, status, login start,
 * login disconnect). Every packet is a VarInt length, then a VarInt packet id and the body.
 * Nothing before login is compressed or encrypted.
 */
final class Packets
{
    /** A handshake or login start is a few hundred bytes; a bigger packet isn't a player. */
    public const MAX_PACKET_BYTES = 2048;

    /**
     * Take one whole packet off the front of the buffer.
     *
     * @return array{0: int, 1: string}|null the packet id and its body, null until it has all arrived
     *
     * @throws ProtocolError
     */
    public static function take(string &$buffer): ?array
    {
        $length = self::varIntAt($buffer, 0);

        if ($length === null) {
            return null;
        }

        [$size, $used] = $length;

        if ($size < 1 || $size > self::MAX_PACKET_BYTES) {
            throw new ProtocolError("Bad packet length {$size}.");
        }

        if (strlen($buffer) < $used + $size) {
            return null;
        }

        $packet = substr($buffer, $used, $size);
        $buffer = (string) substr($buffer, $used + $size);
        $offset = 0;
        $id = self::readVarInt($packet, $offset);

        return [$id, (string) substr($packet, $offset)];
    }

    /**
     * A VarInt at $offset of a buffer that may not have all of it yet.
     *
     * @return array{0: int, 1: int}|null the value and how many bytes it took, null when more are needed
     *
     * @throws ProtocolError
     */
    public static function varIntAt(string $buffer, int $offset): ?array
    {
        $value = 0;

        for ($i = 0; $i < 5; $i++) {
            if (! isset($buffer[$offset + $i])) {
                return null;
            }

            $byte = ord($buffer[$offset + $i]);
            $value |= ($byte & 0x7F) << (7 * $i);

            if (($byte & 0x80) === 0) {
                // 32-bit two's complement, as Java reads it.
                $value &= 0xFFFFFFFF;

                return [$value >= 0x80000000 ? $value - 0x100000000 : $value, $i + 1];
            }
        }

        throw new ProtocolError('VarInt too long.');
    }

    /**
     * @throws ProtocolError when the body ends first
     */
    public static function readVarInt(string $body, int &$offset): int
    {
        $read = self::varIntAt($body, $offset) ?? throw new ProtocolError('The packet ends inside a VarInt.');
        $offset += $read[1];

        return $read[0];
    }

    /**
     * A UTF-8 string with a VarInt byte length, at most $maxChars characters (the protocol's limit).
     *
     * @throws ProtocolError
     */
    public static function readString(string $body, int &$offset, int $maxChars): string
    {
        $bytes = self::readVarInt($body, $offset);

        if ($bytes < 0 || $bytes > $maxChars * 3 || $offset + $bytes > strlen($body)) {
            throw new ProtocolError('Bad string length.');
        }

        $value = substr($body, $offset, $bytes);
        $offset += $bytes;

        if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maxChars) {
            throw new ProtocolError('Bad string.');
        }

        return $value;
    }

    /**
     * @throws ProtocolError
     */
    public static function readUnsignedShort(string $body, int &$offset): int
    {
        if ($offset + 2 > strlen($body)) {
            throw new ProtocolError('The packet ends inside a short.');
        }

        $value = unpack('n', substr($body, $offset, 2));
        $offset += 2;

        return is_array($value) ? (int) $value[1] : 0;
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

    public static function string(string $value): string
    {
        return self::varInt(strlen($value)).$value;
    }

    public static function packet(int $id, string $body): string
    {
        $packet = self::varInt($id).$body;

        return self::varInt(strlen($packet)).$packet;
    }

    /**
     * JSON as the game reads it: no escaped slashes, real UTF-8.
     *
     * @param  array<string, mixed>  $value
     */
    public static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
