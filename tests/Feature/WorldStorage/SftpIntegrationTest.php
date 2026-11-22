<?php

namespace Tests\Feature\WorldStorage;

use App\WorldStorage\StorageSettings;
use App\WorldStorage\WorldStorage;
use Tests\TestCase;

/**
 * Against a real SFTP server (#5288): CI runs an atmoz/sftp service container and sets
 * MVM_SFTP_TEST_HOST (+ _PORT, _USER, _PASSWORD). Skipped everywhere else.
 */
class SftpIntegrationTest extends TestCase
{
    private function settings(?string $password = null): StorageSettings
    {
        $host = getenv('MVM_SFTP_TEST_HOST');

        if (! is_string($host) || $host === '') {
            $this->markTestSkipped('No SFTP test server (MVM_SFTP_TEST_HOST).');
        }

        return new StorageSettings(
            driver: 'sftp',
            host: $host,
            port: (int) (getenv('MVM_SFTP_TEST_PORT') ?: 22),
            username: (string) (getenv('MVM_SFTP_TEST_USER') ?: 'mvm'),
            password: $password ?? (string) (getenv('MVM_SFTP_TEST_PASSWORD') ?: 'mvm-test'),
            privateKey: null,
            root: 'upload/minecraft-worlds',
            ftps: true,
            hostKey: null,
        );
    }

    private function storage(): WorldStorage
    {
        return new WorldStorage(fake: false, fakeRoot: '');
    }

    public function test_the_connection_test_passes_and_reports_the_host_key(): void
    {
        $result = $this->storage()->test($this->settings(), null, false);

        $this->assertTrue($result->ok, $result->message);
        $this->assertNotNull($result->hostKey);
        $this->assertStringStartsWith('SHA256:', (string) StorageSettings::fingerprint($result->hostKey));
    }

    public function test_files_round_trip_with_the_pinned_key(): void
    {
        $storage = $this->storage();
        $hostKey = $storage->test($this->settings(), null, false)->hostKey;
        $disk = $storage->disk($this->settings()->withHostKey($hostKey));
        $bytes = random_bytes(300_000);

        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $disk->writeStream('it-test/world.tar.gz', $stream);

        $this->assertSame(300_000, $disk->size('it-test/world.tar.gz'));
        $this->assertSame(hash('sha256', $bytes), hash('sha256', (string) $disk->get('it-test/world.tar.gz')));

        $disk->delete('it-test/world.tar.gz');
        $this->assertFalse($disk->exists('it-test/world.tar.gz'));
    }

    public function test_another_host_key_is_refused(): void
    {
        $other = 'ssh-ed25519 '.base64_encode(str_repeat('x', 51));

        $result = $this->storage()->test($this->settings(), $other, false);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('host key has changed', $result->message);

        // The disk refuses it too (Flysystem checks the fingerprint).
        $this->expectException(\Throwable::class);
        $this->storage()->disk($this->settings()->withHostKey($other))->put('it-test/x', 'x');
    }

    public function test_a_wrong_password_fails_without_echoing_it(): void
    {
        $result = $this->storage()->test($this->settings('definitely-wrong-pw'), null, false);

        $this->assertFalse($result->ok);
        $this->assertStringNotContainsString('definitely-wrong-pw', $result->message);
    }
}
