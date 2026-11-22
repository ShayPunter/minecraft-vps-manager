<?php

namespace App\Servers;

/**
 * Runs a command on a Minecraft VPS as root. SshRemoteShell logs in with the app's SSH key;
 * FakeRemoteShell stands in for it in tests and E2E.
 */
interface RemoteShell
{
    /**
     * @throws RemoteShellException when it can't connect or log in
     */
    public function run(string $host, string $command, int $timeoutSeconds): ShellResult;
}
