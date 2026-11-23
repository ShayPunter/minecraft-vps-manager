<?php

namespace App\Waker;

/**
 * One connection to the waker (#5620), as a state machine over the bytes that arrive.
 *
 *   handshake → status: Status Request → the server-list entry; Ping → Pong; done.
 *   handshake → login (or transfer, 1.20.5+): Login Start → Login Disconnect with what
 *               happened (started, starting, why not); done.
 *
 * Only a login can start a server. A plain ping never does: anything scanning port 25565
 * gets the list entry and nothing else. The old pre-1.7 ping (0xFE) is hung up on.
 */
final class Session
{
    /** Bytes kept waiting for the rest of a packet: more than two whole packets is junk. */
    private const MAX_BUFFER_BYTES = 2 * Packets::MAX_PACKET_BYTES + 16;

    private string $buffer = '';

    private string $state = 'handshake';

    private string $host = '';

    private bool $done = false;

    public function __construct(
        private readonly Replies $replies,
        private readonly string $ip,
    ) {}

    /**
     * Feed what arrived; returns the bytes to send back.
     *
     * @throws ProtocolError when it isn't Minecraft (the caller hangs up)
     */
    public function receive(string $bytes): string
    {
        if ($this->done) {
            return '';
        }

        $this->buffer .= $bytes;

        if (strlen($this->buffer) > self::MAX_BUFFER_BYTES) {
            throw new ProtocolError('Too much data.');
        }

        if ($this->state === 'handshake') {
            $this->refuseEarly();
        }

        $out = '';

        while (($packet = Packets::take($this->buffer)) !== null) {
            $out .= $this->handle($packet[0], $packet[1]);

            if ($this->done()) {
                break;
            }
        }

        return $out;
    }

    /**
     * Whether everything has been said: send what's left, then hang up.
     */
    public function done(): bool
    {
        return $this->done;
    }

    /**
     * The address the player typed, once the handshake is in (for logs).
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * Hang up on what can't be a handshake as soon as it shows, instead of waiting for the
     * timeout: the old 0xFE ping, or a first packet whose id isn't 0 (an HTTP request reads
     * as a 71-byte packet with id 69).
     */
    private function refuseEarly(): void
    {
        if ($this->buffer === '') {
            return;
        }

        if (ord($this->buffer[0]) === 0xFE) {
            throw new ProtocolError('Legacy ping.');
        }

        $length = Packets::varIntAt($this->buffer, 0);

        if ($length !== null && isset($this->buffer[$length[1]]) && ord($this->buffer[$length[1]]) !== 0x00) {
            throw new ProtocolError('Not a handshake.');
        }
    }

    private function handle(int $id, string $body): string
    {
        return match ($this->state) {
            'handshake' => $this->handshake($id, $body),
            'status' => $this->status($id, $body),
            'login' => $this->login($id, $body),
            default => throw new ProtocolError("Unexpected packet in {$this->state}."),
        };
    }

    private function handshake(int $id, string $body): string
    {
        if ($id !== 0x00) {
            throw new ProtocolError("Expected a handshake, got packet {$id}.");
        }

        $offset = 0;
        Packets::readVarInt($body, $offset); // the client's protocol version: any will do
        $this->host = self::normaliseHost(Packets::readString($body, $offset, 255));
        Packets::readUnsignedShort($body, $offset);
        $next = Packets::readVarInt($body, $offset);

        $this->state = match ($next) {
            1 => 'status',
            2, 3 => 'login',
            default => throw new ProtocolError("Unknown next state {$next}."),
        };

        return '';
    }

    private function status(int $id, string $body): string
    {
        if ($id === 0x00) {
            return Packets::packet(0x00, Packets::string(Packets::json($this->replies->status($this->host))));
        }

        if ($id === 0x01 && strlen($body) === 8) {
            $this->done = true;

            return Packets::packet(0x01, $body);
        }

        throw new ProtocolError("Unexpected status packet {$id}.");
    }

    private function login(int $id, string $body): string
    {
        if ($id !== 0x00) {
            throw new ProtocolError("Expected Login Start, got packet {$id}.");
        }

        // The name comes first in every version since 1.7; what follows (a UUID, keys) varies.
        try {
            $offset = 0;
            $player = Packets::readString($body, $offset, 16);
        } catch (ProtocolError) {
            $player = null;
        }

        $this->done = true;
        $message = $this->replies->join($this->host, $player, $this->ip);

        // Login Disconnect: a JSON text component, in the login state of every version.
        return Packets::packet(0x00, Packets::string(Packets::json(['text' => $message])));
    }

    /**
     * Forge clients add "\0FML3\0" (and older ones "\0FML\0") to the address; some add the
     * root's trailing dot.
     */
    public static function normaliseHost(string $host): string
    {
        return strtolower(rtrim(explode("\0", $host)[0], '.'));
    }
}
