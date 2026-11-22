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

    public function run(string $host, string $command, int $timeoutSeconds): ShellResult
    {
        $state = $this->state();
        $state['commands'][] = ['host' => $host, 'command' => $command];
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
     * @return array{commands: list<array{host: string, command: string}>, exit: int, output: string, unreachable: string|null}
     */
    private function state(): array
    {
        $state = $this->cache->get(self::KEY);

        /** @var array{commands: list<array{host: string, command: string}>, exit: int, output: string, unreachable: string|null} */
        return is_array($state) ? $state : ['commands' => [], 'exit' => 0, 'output' => '', 'unreachable' => null];
    }
}
