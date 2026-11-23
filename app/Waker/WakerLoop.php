<?php

namespace App\Waker;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The waker's sockets (#5620): one listening TCP socket and the connections on it, served
 * one stream_select() round at a time by tick(), so `servers:waker` and the tests drive the
 * same code. Non-blocking throughout; each connection gets a Session.
 *
 * Limits, since port 25565 is scanned all day: at most $maxConnections at once and
 * $maxPerIp from one address, $timeoutSeconds per connection, and Session's packet limits.
 * After the last answer the write side is shut and the rest is read away for up to
 * CLOSE_SECONDS, so the message isn't lost to a reset.
 */
final class WakerLoop
{
    private const CLOSE_SECONDS = 2.0;

    private const READ_BYTES = 4096;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array{socket: resource, session: Session, ip: string, out: string, deadline: float, closing: bool}> */
    private array $connections = [];

    private int $nextId = 1;

    public function __construct(
        private readonly Replies $replies,
        private readonly int $maxConnections = 256,
        private readonly int $maxPerIp = 8,
        private readonly float $timeoutSeconds = 10.0,
    ) {}

    /**
     * Start listening; returns the address actually bound (port 0 picks a free one).
     *
     * @throws RuntimeException when the port can't be had
     */
    public function listen(string $host, int $port): string
    {
        $address = str_contains($host, ':') ? "tcp://[{$host}]:{$port}" : "tcp://{$host}:{$port}";
        $server = @stream_socket_server($address, $errorCode, $errorMessage);

        if ($server === false) {
            throw new RuntimeException("Can't listen on {$host}:{$port}: {$errorMessage} ({$errorCode})");
        }

        stream_set_blocking($server, false);
        $this->server = $server;

        return (string) stream_socket_get_name($server, false);
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Wait up to $waitSeconds for something to happen, then handle it.
     */
    public function tick(float $waitSeconds): void
    {
        if ($this->server === null) {
            throw new RuntimeException('Call listen() first.');
        }

        $read = [$this->server];
        $write = [];

        foreach ($this->connections as $connection) {
            $read[] = $connection['socket'];

            if ($connection['out'] !== '') {
                $write[] = $connection['socket'];
            }
        }

        $except = null;
        $seconds = (int) floor($waitSeconds);
        $micro = (int) (($waitSeconds - $seconds) * 1_000_000);

        if (@stream_select($read, $write, $except, $seconds, $micro) === false) {
            // Interrupted (a signal): nothing to do this round.
            return;
        }

        foreach ($read as $socket) {
            if ($socket === $this->server) {
                $this->accept();
            } else {
                $this->read($socket);
            }
        }

        foreach ($write as $socket) {
            $this->write($socket);
        }

        $this->expire();
    }

    public function close(): void
    {
        foreach (array_keys($this->connections) as $id) {
            $this->drop($id);
        }

        if ($this->server !== null) {
            fclose($this->server);
            $this->server = null;
        }
    }

    private function accept(): void
    {
        if ($this->server === null) {
            return;
        }

        $socket = @stream_socket_accept($this->server, 0, $peer);

        if ($socket === false) {
            return;
        }

        $ip = self::ipOf((string) $peer);
        $fromIp = count(array_filter($this->connections, fn (array $c): bool => $c['ip'] === $ip));

        if (count($this->connections) >= $this->maxConnections || $fromIp >= $this->maxPerIp) {
            fclose($socket);

            return;
        }

        stream_set_blocking($socket, false);
        $this->connections[$this->nextId++] = [
            'socket' => $socket,
            'session' => new Session($this->replies, $ip),
            'ip' => $ip,
            'out' => '',
            'deadline' => microtime(true) + $this->timeoutSeconds,
            'closing' => false,
        ];
    }

    /**
     * @param  resource  $socket
     */
    private function read($socket): void
    {
        $id = $this->idOf($socket);

        if ($id === null) {
            return;
        }

        $data = @fread($socket, self::READ_BYTES);

        if ($data === false || ($data === '' && feof($socket))) {
            $this->drop($id);

            return;
        }

        if ($this->connections[$id]['closing'] || $data === '') {
            return;
        }

        try {
            $this->connections[$id]['out'] .= $this->connections[$id]['session']->receive($data);
        } catch (ProtocolError) {
            $this->drop($id);

            return;
        } catch (Throwable $e) {
            Log::error("Waker: answering {$this->connections[$id]['ip']} failed: {$e->getMessage()}");
            $this->drop($id);

            return;
        }

        $this->finishIfDone($id);
    }

    /**
     * @param  resource  $socket
     */
    private function write($socket): void
    {
        $id = $this->idOf($socket);

        if ($id === null || $this->connections[$id]['out'] === '') {
            return;
        }

        $sent = @fwrite($socket, $this->connections[$id]['out']);

        if ($sent === false) {
            $this->drop($id);

            return;
        }

        $this->connections[$id]['out'] = (string) substr($this->connections[$id]['out'], $sent);
        $this->finishIfDone($id);
    }

    private function finishIfDone(int $id): void
    {
        $connection = $this->connections[$id] ?? null;

        if ($connection === null || $connection['closing'] || $connection['out'] !== '' || ! $connection['session']->done()) {
            return;
        }

        @stream_socket_shutdown($connection['socket'], STREAM_SHUT_WR);
        $this->connections[$id]['closing'] = true;
        $this->connections[$id]['deadline'] = microtime(true) + self::CLOSE_SECONDS;
    }

    private function expire(): void
    {
        $now = microtime(true);

        foreach ($this->connections as $id => $connection) {
            if ($connection['deadline'] <= $now) {
                $this->drop($id);
            }
        }
    }

    private function drop(int $id): void
    {
        if (isset($this->connections[$id])) {
            @fclose($this->connections[$id]['socket']);
            unset($this->connections[$id]);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function idOf($socket): ?int
    {
        foreach ($this->connections as $id => $connection) {
            if ($connection['socket'] === $socket) {
                return $id;
            }
        }

        return null;
    }

    /**
     * "1.2.3.4:5678" or "[::1]:5678" → the address alone.
     */
    public static function ipOf(string $peer): string
    {
        if (str_starts_with($peer, '[')) {
            $end = strpos($peer, ']');

            return $end === false ? $peer : substr($peer, 1, $end - 1);
        }

        $colon = strrpos($peer, ':');

        return $colon === false ? $peer : substr($peer, 0, $colon);
    }
}
