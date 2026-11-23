<?php

namespace Tests\Feature\Servers;

use App\Servers\RemoteShellException;
use App\Servers\SshKeyPair;
use App\Servers\SshRemoteShell;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Host-key pinning (#5527) against a real sshd: CI's atmoz/sftp service (MVM_SFTP_TEST_HOST,
 * the same one SftpIntegrationTest uses). Our key isn't on that server, so a login that gets
 * past the host-key check ends at "refused"; a wrong pinned key never gets that far.
 * Skipped everywhere else.
 */
class SshHostKeyIntegrationTest extends TestCase
{
    private string $host;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        $host = getenv('MVM_SFTP_TEST_HOST');

        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('No SSH test server (MVM_SFTP_TEST_HOST).');
        }

        $this->host = $host;
        $this->port = (int) (getenv('MVM_SFTP_TEST_PORT') ?: 22);
    }

    private function shell(): SshRemoteShell
    {
        return new SshRemoteShell('/nonexistent', 'mvm', $this->port);
    }

    private function realHostKey(): string
    {
        $ssh = new SSH2($this->host, $this->port, 10);
        $ssh->setPreferredAlgorithms(['hostkey' => ['ssh-ed25519']]);
        $key = $ssh->getServerPublicHostKey();
        $ssh->disconnect();
        $this->assertIsString($key);

        return $key;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function calls(): array
    {
        return ['run' => ['run'], 'putFiles' => ['putFiles']];
    }

    private function connect(string $method, string $hostKey): void
    {
        $login = SshKeyPair::generate('mvm-test')->privateKey;

        $method === 'run'
            ? $this->shell()->run($this->host, 'true', 10, $login, $hostKey)
            : $this->shell()->putFiles($this->host, ['/tmp/x' => ['x', 0600]], $login, $hostKey);
    }

    #[DataProvider('calls')]
    public function test_a_different_host_key_stops_before_logging_in(string $method): void
    {
        $this->expectException(RemoteShellException::class);
        $this->expectExceptionMessage("didn't show the SSH host key made for this VPS");

        $this->connect($method, SshKeyPair::generate('someone-else')->publicKey);
    }

    #[DataProvider('calls')]
    public function test_the_right_host_key_gets_as_far_as_the_login(string $method): void
    {
        $this->expectException(RemoteShellException::class);
        $this->expectExceptionMessage('login to '.$this->host.' was refused');

        $this->connect($method, $this->realHostKey().' comment-ignored');
    }
}
