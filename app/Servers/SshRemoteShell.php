<?php

namespace App\Servers;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * SSH as root with the app's private key (the half of HETZNER_SSH_PUBLIC_KEY that Hetzner
 * puts on every VPS). The host key isn't checked: every VPS is new, made by this app a few
 * minutes earlier, and Hetzner doesn't report its host key, so there's nothing to compare with.
 */
class SshRemoteShell implements RemoteShell
{
    public function __construct(
        private readonly string $privateKeyPath,
        private readonly string $user = 'root',
    ) {}

    public function run(string $host, string $command, int $timeoutSeconds): ShellResult
    {
        if (! is_readable($this->privateKeyPath)) {
            throw new RemoteShellException("The SSH private key isn't readable at {$this->privateKeyPath} (HETZNER_SSH_PRIVATE_KEY).");
        }

        try {
            $key = PublicKeyLoader::load((string) file_get_contents($this->privateKeyPath));
        } catch (Throwable $e) {
            throw new RemoteShellException("Couldn't read the SSH private key: {$e->getMessage()}");
        }

        if (! $key instanceof PrivateKey) {
            throw new RemoteShellException('HETZNER_SSH_PRIVATE_KEY points at a public key, not the private one.');
        }

        try {
            $ssh = new SSH2($host, 22, 10);

            if (! $ssh->login($this->user, $key)) {
                throw new RemoteShellException("SSH login to {$host} was refused.");
            }

            $ssh->setTimeout($timeoutSeconds);
            $output = $ssh->exec($command);
            $timedOut = $ssh->isTimeout();
            $exitCode = $ssh->getExitStatus();
            $ssh->disconnect();
        } catch (RemoteShellException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RemoteShellException("SSH to {$host} failed: {$e->getMessage()}");
        }

        if ($timedOut) {
            throw new RemoteShellException("The command on {$host} didn't finish within {$timeoutSeconds} seconds.");
        }

        return new ShellResult(is_int($exitCode) ? $exitCode : 255, is_string($output) ? $output : '');
    }
}
