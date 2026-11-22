<?php

namespace App\Servers;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * SSH as root with the run's own private key (#5324; Hetzner put its public half on the VPS).
 * A run started before #5324 has none and falls back to the old key file, if it's still there.
 * The host key isn't checked: every VPS is new, made by this app a few minutes earlier, and
 * Hetzner doesn't report its host key, so there's nothing to compare with.
 */
class SshRemoteShell implements RemoteShell
{
    /**
     * @param  string  $legacyPrivateKeyPath  the hand-made key runs before #5324 used
     */
    public function __construct(
        private readonly string $legacyPrivateKeyPath,
        private readonly string $user = 'root',
    ) {}

    public function run(string $host, string $command, int $timeoutSeconds, ?string $privateKey = null): ShellResult
    {
        if ($privateKey === null) {
            if (! is_readable($this->legacyPrivateKeyPath)) {
                throw new RemoteShellException("This run has no SSH key of its own, and there's no old key file at {$this->legacyPrivateKeyPath}.");
            }

            $privateKey = (string) file_get_contents($this->legacyPrivateKeyPath);
        }

        try {
            $key = PublicKeyLoader::load($privateKey);
        } catch (Throwable $e) {
            throw new RemoteShellException("Couldn't read the SSH private key: {$e->getMessage()}");
        }

        if (! $key instanceof PrivateKey) {
            throw new RemoteShellException('The SSH key is a public key, not the private one.');
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
