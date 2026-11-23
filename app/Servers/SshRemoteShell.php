<?php

namespace App\Servers;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * SSH as root with the run's own private key (#5324; Hetzner put its public half on the VPS).
 * A run started before #5324 has none and falls back to the old key file, if it's still there.
 * The host key is pinned (#5527): the app makes the VPS's ed25519 host key itself, cloud-init
 * installs it, and every login checks the VPS proves it holds that key before sending ours.
 * Runs from before #5527 have no pinned key and aren't checked.
 */
class SshRemoteShell implements RemoteShell
{
    /**
     * @param  string  $legacyPrivateKeyPath  the hand-made key runs before #5324 used
     */
    public function __construct(
        private readonly string $legacyPrivateKeyPath,
        private readonly string $user = 'root',
        private readonly int $port = 22,
    ) {}

    public function run(string $host, string $command, int $timeoutSeconds, ?string $privateKey = null, ?string $hostKey = null): ShellResult
    {
        $key = $this->loadKey($privateKey);

        try {
            $ssh = new SSH2($host, $this->port, 10);
            self::checkHostKey($ssh, $host, $hostKey);

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

    public function putFiles(string $host, array $files, ?string $privateKey = null, ?string $hostKey = null): void
    {
        $key = $this->loadKey($privateKey);

        try {
            $sftp = new SFTP($host, $this->port, 10);
            self::checkHostKey($sftp, $host, $hostKey);

            if (! $sftp->login($this->user, $key)) {
                throw new RemoteShellException("SFTP login to {$host} was refused.");
            }

            foreach (array_unique(array_map('dirname', array_keys($files))) as $dir) {
                if (! $sftp->is_dir($dir) && ! $sftp->mkdir($dir, 0700, true)) {
                    throw new RemoteShellException("Couldn't make {$dir} on {$host}.");
                }

                $sftp->chmod(0700, $dir);
            }

            foreach ($files as $path => [$contents, $mode]) {
                // An empty file with the final mode first, then the contents: a secret never
                // sits in a file others could read, even for a moment.
                if (! $sftp->put($path, '') || $sftp->chmod($mode, $path) === false || ! $sftp->put($path, $contents)) {
                    throw new RemoteShellException("Couldn't write {$path} on {$host}.");
                }
            }

            $sftp->disconnect();
        } catch (RemoteShellException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RemoteShellException("SFTP to {$host} failed: {$e->getMessage()}");
        }
    }

    /**
     * `ssh-ed25519 AAAA…` without the comment: what a run keeps and what a login compares.
     */
    public static function keyBlob(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey));

        if ($parts === false || count($parts) < 2 || base64_decode($parts[1], true) === false) {
            throw new RemoteShellException("That isn't an OpenSSH public key.");
        }

        return $parts[0].' '.$parts[1];
    }

    /**
     * Before anything of ours is sent: only an ed25519 host key is offered, and it must be the
     * one made for this VPS. phpseclib checks the VPS signed the key exchange with it.
     */
    private static function checkHostKey(SSH2 $ssh, string $host, ?string $hostKey): void
    {
        if ($hostKey === null) {
            return;
        }

        $expected = self::keyBlob($hostKey);
        $ssh->setPreferredAlgorithms(['hostkey' => [explode(' ', $expected)[0]]]);
        $shown = $ssh->getServerPublicHostKey();

        if (! is_string($shown) || self::keyBlob($shown) !== $expected) {
            $ssh->disconnect();

            throw new RemoteShellException("{$host} didn't show the SSH host key made for this VPS: not logging in.");
        }
    }

    private function loadKey(?string $privateKey): PrivateKey
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

        return $key;
    }
}
