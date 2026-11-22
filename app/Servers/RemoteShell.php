<?php

namespace App\Servers;

/**
 * Runs a command on a Minecraft VPS as root. SshRemoteShell logs in with the run's own SSH
 * key (#5324); FakeRemoteShell stands in for it in tests and E2E.
 */
interface RemoteShell
{
    /**
     * @param  string|null  $privateKey  the run's OpenSSH private key; null for a run started
     *                                   before #5324, which falls back to the old key file
     *
     * @throws RemoteShellException when it can't connect or log in
     */
    public function run(string $host, string $command, int $timeoutSeconds, ?string $privateKey = null): ShellResult;

    /**
     * Write files on the VPS over SFTP (#5288), so their contents (the world storage login)
     * never appear in a command line. Parent folders are made with mode 0700.
     *
     * @param  array<string, array{0: string, 1: int}>  $files  absolute path => [contents, mode]
     *
     * @throws RemoteShellException
     */
    public function putFiles(string $host, array $files, ?string $privateKey = null): void;
}
