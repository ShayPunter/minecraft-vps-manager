<?php

namespace Tests\Feature\Servers;

use App\Servers\RemoteShellException;
use App\Servers\SshKeyPair;
use App\Servers\SshRemoteShell;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

/**
 * Host-key pinning (#5527) against a real sshd: CI's atmoz/sftp service (MVM_SFTP_TEST_HOST,
 * the same one SftpIntegrationTest uses). Our key isn't on that server, so a login that gets
 * past the host-key check ends at "refused"; a wrong pinned key never gets that far.
 * Skipped everywhere else.
 *
 * OpenSSH (9.8+) penalises a source for failed and unauthenticated connections and refuses it
 * once the penalties add up to 15 s (PerSourcePenalties). This class makes few connections
 * (about 8 s of penalty) and then waits them out, or SftpIntegrationTest, which runs next
 * against the same server, gets "Connection closed by server".
 */
class SshHostKeyIntegrationTest extends TestCase
{
    private string $host;

    private int $port;

    private static bool $connected = false;

    protected function setUp(): void
    {
        parent::setUp();
        $host = getenv('MVM_SFTP_TEST_HOST');

        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('No SSH test server (MVM_SFTP_TEST_HOST).');
        }

        $this->host = $host;
        $this->port = (int) (getenv('MVM_SFTP_TEST_PORT') ?: 22);
        self::$connected = true;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$connected) {
            sleep(10);
        }

        parent::tearDownAfterClass();
    }

    private function shell(): SshRemoteShell
    {
        return new SshRemoteShell('/nonexistent', 'mvm', $this->port);
    }

    private function login(): string
    {
        return SshKeyPair::generate('mvm-test')->privateKey;
    }

    /**
     * Expect $call to throw a RemoteShellException containing $message.
     */
    private function assertRefused(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('Expected a RemoteShellException.');
        } catch (RemoteShellException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_only_the_pinned_host_key_gets_as_far_as_the_login(): void
    {
        $other = SshKeyPair::generate('someone-else')->publicKey;

        // A different pinned key: refused before anything of ours is sent, for both calls.
        $this->assertRefused(fn () => $this->shell()->run($this->host, 'true', 10, $this->login(), $other), "didn't show the SSH host key made for this VPS");
        $this->assertRefused(fn () => $this->shell()->putFiles($this->host, ['/tmp/x' => ['x', 0600]], $this->login(), $other), "didn't show the SSH host key made for this VPS");

        // The server's real key (a comment after it is ignored): on to the login, which this
        // server refuses because our key isn't on it.
        $ssh = new SSH2($this->host, $this->port, 10);
        $ssh->setPreferredAlgorithms(['hostkey' => ['ssh-ed25519']]);
        $real = $ssh->getServerPublicHostKey();
        $ssh->disconnect();
        $this->assertIsString($real);

        $this->assertRefused(fn () => $this->shell()->run($this->host, 'true', 10, $this->login(), $real.' comment-ignored'), 'SSH login to '.$this->host.' was refused');
    }
}
