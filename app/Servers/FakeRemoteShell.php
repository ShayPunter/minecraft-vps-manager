<?php

namespace App\Servers;

use Illuminate\Contracts\Cache\Repository;

/**
 * Stands in for SshRemoteShell in tests and E2E. Every command succeeds unless a test calls
 * `failWith()` (a non-zero exit) or `unreachable()` (no connection). Commands are recorded
 * in the cache, so a test can check what was run.
 */
class FakeRemoteShell implements RemoteShell
{
    private const KEY = 'fake-shell';

    public function __construct(private readonly Repository $cache) {}

    public function run(string $host, string $command, int $timeoutSeconds, ?string $privateKey = null): ShellResult
    {
        $state = $this->state();
        $state['commands'][] = ['host' => $host, 'command' => $command];
        // Which key logged in (so a test can check it was the run's), kept apart from
        // `commands` so the existing assertions on host + command stay as they were.
        $state['keys'][] = $privateKey;
        $this->cache->forever(self::KEY, $state);

        if ($state['unreachable'] !== null) {
            throw new RemoteShellException($state['unreachable']);
        }

        return $state['exit'] === 0
            ? new ShellResult(0, "result=success\n")
            : new ShellResult($state['exit'], $state['output']);
    }

    public function failWith(int $exitCode, string $output): void
    {
        $this->cache->forever(self::KEY, [...$this->state(), 'exit' => $exitCode, 'output' => $output]);
    }

    public function unreachable(string $message = 'Connection timed out'): void
    {
        $this->cache->forever(self::KEY, [...$this->state(), 'unreachable' => $message]);
    }

    public function succeed(): void
    {
        $this->cache->forever(self::KEY, [...$this->state(), 'exit' => 0, 'output' => '', 'unreachable' => null]);
    }

    /**
     * @return list<array{host: string, command: string}>
     */
    public function commands(): array
    {
        return $this->state()['commands'];
    }

    /**
     * The private key each command logged in with, in the same order as commands().
     *
     * @return list<string|null>
     */
    public function keys(): array
    {
        return $this->state()['keys'];
    }

    /**
     * @return array{commands: list<array{host: string, command: string}>, keys: list<string|null>, exit: int, output: string, unreachable: string|null}
     */
    private function state(): array
    {
        $state = $this->cache->get(self::KEY);
        $default = ['commands' => [], 'keys' => [], 'exit' => 0, 'output' => '', 'unreachable' => null];

        /** @var array{commands: list<array{host: string, command: string}>, keys: list<string|null>, exit: int, output: string, unreachable: string|null} */
        return is_array($state) ? [...$default, ...$state] : $default;
    }
}
