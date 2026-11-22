<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Models\WorldArchive;
use App\Servers\WorldSync;
use App\WorldStorage\S3Upload;
use App\WorldStorage\StorageSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The world scripts (#5288). Checked for real against atmoz/sftp (password with a space,
 * quote and backslash; and a private key) and a plain FTP server, from an Ubuntu 24.04
 * container: upload, read-back check, rename, download, SHA-256 refusal, host key pinning.
 */
class WorldSyncScriptTest extends TestCase
{
    private const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGZha2Utd29ybGQtc3RvcmFnZS1ob3N0LWtleQ==';

    private function server(string $slug = 'vanilla'): Server
    {
        $server = new Server;
        $server->slug = $slug;

        return $server;
    }

    private function sftp(array $overrides = []): StorageSettings
    {
        $values = [
            'driver' => 'sftp', 'host' => 'u1.your-storagebox.de', 'port' => 23, 'username' => 'u1-sub1',
            'password' => 'pa ss"w\\ord', 'privateKey' => null, 'root' => 'worlds', 'ftps' => true, 'hostKey' => self::HOST_KEY,
            ...$overrides,
        ];

        return new StorageSettings(...$values);
    }

    private function archive(string $path = 'vanilla/20261122-190000-abcdef-save.zip'): WorldArchive
    {
        $archive = new WorldArchive;
        $archive->path = $path;
        $archive->size = 1234;
        $archive->sha256 = str_repeat('c', 64);

        return $archive;
    }

    public function test_sftp_pins_the_host_key_with_the_port(): void
    {
        $files = WorldSync::saveFiles($this->server(), $this->sftp());

        $this->assertSame("[u1.your-storagebox.de]:23 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGZha2Utd29ybGQtc3RvcmFnZS1ob3N0LWtleQ==\n", $files['/root/.mvm/known_hosts'][0]);
        $this->assertSame(['pa ss"w\\ord', 0600], $files['/root/.mvm/password']);
        $this->assertSame(0700, $files['/root/.mvm/askpass'][1]);

        $script = $files['/root/.mvm/save.sh'][0];
        $this->assertStringContainsString('-P 23 ', $script);
        $this->assertStringContainsString('SSH_ASKPASS_REQUIRE=force', $script);
        // BatchMode=no before -b, or sftp's own BatchMode=yes wins and askpass is never used.
        $this->assertLessThan(strpos($script, '-b "$M/work/batch"'), strpos($script, '-o BatchMode=no'));
        $this->assertStringNotContainsString('pa ss', $script);
    }

    public function test_a_private_key_is_used_instead_of_the_password(): void
    {
        $files = WorldSync::saveFiles($this->server(), $this->sftp(['password' => null, 'privateKey' => "-----BEGIN OPENSSH PRIVATE KEY-----\r\nabc\r\n-----END OPENSSH PRIVATE KEY-----"]));

        $this->assertSame("-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----\n", $files['/root/.mvm/key'][0]);
        $this->assertArrayNotHasKey('/root/.mvm/password', $files);
        $this->assertStringContainsString('-i "$M/key" -o IdentitiesOnly=yes', $files['/root/.mvm/save.sh'][0]);
        $this->assertStringNotContainsString('SSH_ASKPASS', $files['/root/.mvm/save.sh'][0]);
    }

    public function test_ftp_escapes_the_login_for_curl_and_requires_tls(): void
    {
        $files = WorldSync::saveFiles($this->server(), $this->sftp(['driver' => 'ftp', 'port' => 21, 'hostKey' => null, 'root' => '/home/worlds']));

        $this->assertSame(["user = \"u1-sub1:pa ss\\\"w\\\\ord\"\n", 0600], $files['/root/.mvm/curl.conf']);
        $script = $files['/root/.mvm/save.sh'][0];
        $this->assertStringContainsString("BASE='ftp://u1.your-storagebox.de:21/%2Fhome/worlds/'", $script);
        $this->assertStringContainsString('--ssl-reqd', $script);

        $plain = WorldSync::saveFiles($this->server(), $this->sftp(['driver' => 'ftp', 'port' => 21, 'hostKey' => null, 'ftps' => false]));
        $this->assertStringNotContainsString('--ssl-reqd', $plain['/root/.mvm/save.sh'][0]);
    }

    public function test_sftp_without_a_pinned_host_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorldSync::saveFiles($this->server(), $this->sftp(['hostKey' => null]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badArchivePaths(): array
    {
        return [
            'other server' => ['modded/20261122-190000-abcdef-save.zip'],
            'traversal' => ['vanilla/../x.zip'],
            'quote' => ["vanilla/a'b.zip"],
            'space' => ['vanilla/a b.zip'],
            'not a zip' => ['vanilla/a.sh'],
            'absolute' => ['/vanilla/a.zip'],
        ];
    }

    #[DataProvider('badArchivePaths')]
    public function test_the_save_command_refuses_odd_paths(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorldSync::saveCommand($this->server(), $path);
    }

    public function test_the_save_command_quotes_the_path(): void
    {
        $this->assertSame("sh /root/.mvm/save.sh 'vanilla/20261122-190000-abcdef-save.zip'", WorldSync::saveCommand($this->server(), 'vanilla/20261122-190000-abcdef-save.zip'));
    }

    public function test_storage_settings_a_shell_must_not_see_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorldSync::saveFiles($this->server(), $this->sftp(['host' => 'evil.example.com; rm -rf /']));
    }

    public function test_prepare_checks_the_download_against_the_saved_size_and_hash(): void
    {
        $files = WorldSync::prepareFiles($this->server(), $this->archive(), $this->sftp());
        $script = $files['/root/.mvm/prepare.sh'][0];

        $this->assertStringContainsString("P='vanilla/20261122-190000-abcdef-save.zip'", $script);
        $this->assertStringContainsString("[ \"\$size\" != '1234' ]", $script);
        $this->assertStringContainsString("[ \"\$sum\" != '".str_repeat('c', 64)."' ]", $script);
        $this->assertStringContainsString("DIR='/opt/minecraft/vanilla'", $script);
        $this->assertStringContainsString('cloud-init status --wait', $script);
        $this->assertStringContainsString('flock -n 9', $script);
    }

    public function test_prepare_without_a_world_needs_no_login(): void
    {
        $files = WorldSync::prepareFiles($this->server(), null, null);

        $this->assertSame(['/root/.mvm/prepare.sh'], array_keys($files));
        $this->assertStringNotContainsString('fetch', $files['/root/.mvm/prepare.sh'][0]);
    }

    private function s3(array $overrides = []): StorageSettings
    {
        return $this->sftp([
            'driver' => 's3', 'host' => 'fsn1.your-objectstorage.com', 'port' => 443, 'username' => 'AKIAKEY',
            'password' => 's3-secret', 'hostKey' => null, 'endpoint' => 'https://fsn1.your-objectstorage.com',
            'region' => 'fsn1', 'bucket' => 'mvm-worlds', ...$overrides,
        ]);
    }

    private function upload(array $partUrls = ['https://s3.test/b/k?partNumber=1&X-Amz-Signature=a', 'https://s3.test/b/k?partNumber=2&X-Amz-Signature=b']): S3Upload
    {
        return new S3Upload('vanilla/20261122-190000-abcdef-save.zip', 'up-1', $partUrls, 64 * 1024 * 1024,
            'https://s3.test/b/k?uploadId=up-1&X-Amz-Signature=c', 'https://s3.test/b/k?X-Amz-Signature=d');
    }

    public function test_s3_save_gets_links_only(): void
    {
        $files = WorldSync::saveFiles($this->server(), $this->s3(), $this->upload());

        $this->assertSame(['/root/.mvm/save.sh', '/root/.mvm/parts', '/root/.mvm/complete.conf', '/root/.mvm/get.conf'], array_keys($files));
        $this->assertSame(
            "url = \"https://s3.test/b/k?partNumber=1&X-Amz-Signature=a\"\nurl = \"https://s3.test/b/k?partNumber=2&X-Amz-Signature=b\"\n",
            $files['/root/.mvm/parts'][0],
        );
        $this->assertSame(0600, $files['/root/.mvm/parts'][1]);
        $this->assertSame("url = \"https://s3.test/b/k?uploadId=up-1&X-Amz-Signature=c\"\n", $files['/root/.mvm/complete.conf'][0]);
        $this->assertSame("url = \"https://s3.test/b/k?X-Amz-Signature=d\"\n", $files['/root/.mvm/get.conf'][0]);

        $script = $files['/root/.mvm/save.sh'][0];
        $this->assertStringContainsString("PART_MB=64\nMAX_PARTS=2\n", $script);
        $this->assertStringContainsString('CompleteMultipartUploadResult', $script);
        $this->assertStringNotContainsString('X-Amz-Signature', $script);

        foreach ($files as $path => [$contents]) {
            $this->assertStringNotContainsString('s3-secret', $contents, $path);
            $this->assertStringNotContainsString('AKIAKEY', $contents, $path);
        }
    }

    public function test_s3_needs_a_started_upload_and_a_download_link(): void
    {
        try {
            WorldSync::saveFiles($this->server(), $this->s3());
            $this->fail('Saved without an upload.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        WorldSync::prepareFiles($this->server(), $this->archive(), $this->s3());
    }

    public function test_s3_prepare_downloads_with_the_link(): void
    {
        $files = WorldSync::prepareFiles($this->server(), $this->archive(), $this->s3(), 'https://s3.test/b/k?X-Amz-Signature=e');

        $this->assertSame(['/root/.mvm/prepare.sh', '/root/.mvm/get.conf'], array_keys($files));
        $this->assertSame("url = \"https://s3.test/b/k?X-Amz-Signature=e\"\n", $files['/root/.mvm/get.conf'][0]);
        $this->assertStringContainsString("[ \"\$sum\" != '".str_repeat('c', 64)."' ]", $files['/root/.mvm/prepare.sh'][0]);
    }

    public function test_links_with_quotes_or_spaces_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorldSync::saveFiles($this->server(), $this->s3(), $this->upload(['https://s3.test/b/k"; rm -rf /']));
    }

    public function test_an_s3_bucket_a_script_must_not_see_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorldSync::saveFiles($this->server(), $this->s3(['bucket' => "b';x"]), $this->upload());
    }

    public function test_the_save_output_is_read(): void
    {
        $sha = str_repeat('d', 64);

        $this->assertSame(['size' => 42, 'sha256' => $sha, 'files' => []], WorldSync::parseSaveOutput("sftp> put \"a\" \"b\"\nsize=42 sha256={$sha}\n"));
        // The world's own server.properties etc. as saved (#5377); other names are ignored.
        $this->assertSame(
            ['size' => 42, 'sha256' => $sha, 'files' => ['server.properties' => "motd=Hi\n"]],
            WorldSync::parseSaveOutput('mvmfile server.properties '.base64_encode("motd=Hi\n")."\nmvmfile passwd ".base64_encode('root:x')."\nsize=42 sha256={$sha}\n"),
        );
        $this->assertNull(WorldSync::parseSaveOutput("empty=1\n"));

        $this->expectException(InvalidArgumentException::class);
        WorldSync::parseSaveOutput('Connection closed');
    }
}
