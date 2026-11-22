<?php

namespace App\Servers;

use App\WorldStorage\WorldStorage;
use Illuminate\Contracts\Cache\Repository;
use ZipArchive;

/**
 * Stands in for SshRemoteShell in tests and E2E. Every command succeeds unless a test calls
 * `failWith()` (a non-zero exit, every command), `failMatching()` (only commands containing
 * some text) or `unreachable()` (no connection). Commands and written files are recorded in
 * the cache, so a test can check what was run.
 *
 * The world scripts (#5288) act like a real VPS would: prepare.sh says "prepared", and
 * save.sh writes a small zip to the (fake) world storage at the path it was given and prints
 * its size and SHA-256, unless `emptyWorld()` makes it report an empty folder.
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

        foreach ($state['fail_matching'] as [$needle, $exit, $output]) {
            if (str_contains($command, $needle)) {
                return new ShellResult($exit, $output);
            }
        }

        if ($state['exit'] !== 0) {
            return new ShellResult($state['exit'], $state['output']);
        }

        if ($command === WorldSync::PREPARE_COMMAND) {
            return new ShellResult(0, "prepared\n");
        }

        if (preg_match('#^'.preg_quote(WorldSync::SAVE_COMMAND, '#')." '([^']+)'$#", $command, $m) === 1) {
            return $this->save($m[1], $state['empty_world']);
        }

        return new ShellResult(0, "result=success\n");
    }

    public function putFiles(string $host, array $files, ?string $privateKey = null): void
    {
        $state = $this->state();

        if ($state['unreachable'] !== null) {
            throw new RemoteShellException($state['unreachable']);
        }

        foreach ($files as $path => [$contents, $mode]) {
            $state['files'][] = ['host' => $host, 'path' => $path, 'contents' => $contents, 'mode' => $mode];
        }

        $this->cache->forever(self::KEY, $state);
    }

    public function failWith(int $exitCode, string $output): void
    {
        $this->cache->forever(self::KEY, [...$this->state(), 'exit' => $exitCode, 'output' => $output]);
    }

    /**
     * Fail only commands that contain $needle (e.g. WorldSync::SAVE_COMMAND).
     */
    public function failMatching(string $needle, int $exitCode, string $output): void
    {
        $state = $this->state();
        $state['fail_matching'][] = [$needle, $exitCode, $output];
        $this->cache->forever(self::KEY, $state);
    }

    public function unreachable(string $message = 'Connection timed out'): void
    {
        $this->cache->forever(self::KEY, [...$this->state(), 'unreachable' => $message]);
    }

    /**
     * save.sh finds an empty server folder (Minecraft never wrote anything).
     */
    public function emptyWorld(): void
    {
        $this->cache->forever(self::KEY, [...$this->state(), 'empty_world' => true]);
    }

    /**
     * Forget what was run and written so far (e.g. the start's prepare.sh), keeping the
     * failure settings, so a test can look at the stop alone.
     */
    public function forget(): void
    {
        $this->cache->forever(self::KEY, [...$this->state(), 'commands' => [], 'keys' => [], 'files' => []]);
    }

    public function succeed(): void
    {
        $this->cache->forever(self::KEY, [...$this->state(), 'exit' => 0, 'output' => '', 'unreachable' => null, 'fail_matching' => []]);
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
     * @return list<array{host: string, path: string, contents: string, mode: int}>
     */
    public function files(): array
    {
        return $this->state()['files'];
    }

    private function save(string $path, bool $empty): ShellResult
    {
        if ($empty) {
            return new ShellResult(0, "empty=1\n");
        }

        $bytes = $this->fakeWorldZip();
        app(WorldStorage::class)->disk()->put($path, $bytes);

        return new ShellResult(0, 'size='.strlen($bytes).' sha256='.hash('sha256', $bytes)."\n");
    }

    private function fakeWorldZip(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'mvm-fake-save');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('world/level.dat', 'saved by the fake VPS at '.now()->toIso8601String());
        $zip->addFromString('server.properties', "motd=fake\n");
        $zip->close();
        $bytes = (string) file_get_contents($file);
        @unlink($file);

        return $bytes;
    }

    /**
     * @return array{commands: list<array{host: string, command: string}>, keys: list<string|null>, files: list<array{host: string, path: string, contents: string, mode: int}>, exit: int, output: string, unreachable: string|null, fail_matching: list<array{0: string, 1: int, 2: string}>, empty_world: bool}
     */
    private function state(): array
    {
        $state = $this->cache->get(self::KEY);
        $default = ['commands' => [], 'keys' => [], 'files' => [], 'exit' => 0, 'output' => '', 'unreachable' => null, 'fail_matching' => [], 'empty_world' => false];

        /** @var array{commands: list<array{host: string, command: string}>, keys: list<string|null>, files: list<array{host: string, path: string, contents: string, mode: int}>, exit: int, output: string, unreachable: string|null, fail_matching: list<array{0: string, 1: int, 2: string}>, empty_world: bool} */
        return is_array($state) ? [...$default, ...$state] : $default;
    }
}
