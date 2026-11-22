<?php

namespace Tests\Feature\WorldStorage;

use App\Models\Server;
use App\Models\WorldArchive;
use App\Servers\WorldSync;
use App\WorldStorage\S3Transfers;
use App\WorldStorage\StorageSettings;
use App\WorldStorage\WorldStorage;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * Against a real S3 API (#5347): CI runs an adobe/s3mock service container and sets
 * MVM_S3_TEST_ENDPOINT (+ _BUCKET). Skipped everywhere else.
 *
 * With MVM_S3_SCRIPT_DIR set as well, test_writes_the_vps_files puts a world archive on the
 * mock and writes prepare.sh/save.sh with real pre-signed links into that folder, so an
 * Ubuntu container on the same network can run them as a VPS would; MVM_S3_SCRIPT_CHECK
 * then checks what save.sh uploaded.
 */
class S3IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TestCase blocks stray HTTP; these tests talk to the mock on purpose.
        Http::allowStrayRequests();
    }

    private function settings(string $root = 'it-test'): StorageSettings
    {
        $endpoint = getenv('MVM_S3_TEST_ENDPOINT');

        if (! is_string($endpoint) || $endpoint === '') {
            $this->markTestSkipped('No S3 test server (MVM_S3_TEST_ENDPOINT).');
        }

        [$host, $port] = StorageSettings::endpointHostAndPort($endpoint, 'us-east-1');

        return new StorageSettings(
            driver: 's3',
            host: $host,
            port: $port,
            username: 'mvm-test-key',
            password: 'mvm-test-secret',
            privateKey: null,
            root: $root,
            ftps: false,
            hostKey: null,
            endpoint: $endpoint,
            region: 'us-east-1',
            bucket: (string) (getenv('MVM_S3_TEST_BUCKET') ?: 'mvm-worlds'),
            pathStyle: true,
        );
    }

    private function storage(): WorldStorage
    {
        return new WorldStorage(fake: false, fakeRoot: '');
    }

    private function transfers(): S3Transfers
    {
        return new S3Transfers($this->storage(), $this->app->make(Repository::class));
    }

    public function test_the_connection_test_passes(): void
    {
        $result = $this->storage()->test($this->settings(), null, false);

        $this->assertTrue($result->ok, $result->message);
        $this->assertNull($result->hostKey);
    }

    public function test_a_missing_bucket_fails_the_test_with_s3s_reason(): void
    {
        $settings = $this->settings();
        $missing = new StorageSettings('s3', $settings->host, $settings->port, $settings->username, $settings->password,
            null, 'it-test', false, null, $settings->endpoint, 'us-east-1', 'no-such-bucket-mvm', true);

        $result = $this->storage()->test($missing, null, false);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Couldn\'t write to bucket "no-such-bucket-mvm"', $result->message);
        $this->assertStringContainsString('NoSuchBucket', $result->message);
        $this->assertStringNotContainsString('mvm-test-secret', $result->message);
    }

    public function test_a_multipart_upload_with_links_alone_round_trips(): void
    {
        $settings = $this->settings();
        $path = 'vanilla/it-'.bin2hex(random_bytes(4)).'-save.zip';
        $partBytes = 5 * 1024 * 1024;
        $bytes = random_bytes($partBytes * 2 + 12345);
        $upload = $this->transfers()->startUpload($settings, $path, 900, $partBytes, 4);

        $this->assertCount(4, $upload->partUrls);

        $parts = '';

        foreach (str_split($bytes, $partBytes) as $i => $chunk) {
            $response = Http::withBody($chunk, 'application/octet-stream')->put($upload->partUrls[$i]);
            $this->assertTrue($response->successful(), $response->body());
            $etag = $response->header('ETag');
            $this->assertNotSame('', $etag);
            $parts .= '<Part><PartNumber>'.($i + 1).'</PartNumber><ETag>'.$etag.'</ETag></Part>';
        }

        $complete = Http::withBody("<CompleteMultipartUpload>{$parts}</CompleteMultipartUpload>", 'application/xml')->post($upload->completeUrl);
        $this->assertStringContainsString('CompleteMultipartUploadResult', $complete->body());

        $readBack = Http::get($upload->readBackUrl);
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $readBack->body()));
        $this->assertSame(strlen($bytes), $this->storage()->disk($settings)->size($path));

        // A download link for a start reads the same object.
        $this->assertSame(hash('sha256', $bytes), hash('sha256', Http::get($this->transfers()->downloadUrl($settings, $path, 300))->body()));

        // A save that didn't count: abort removes the object.
        $this->assertNotNull($this->transfers()->abort($settings, $upload), 'The upload was already complete, so abort says so.');
        $this->assertFalse($this->storage()->disk($settings)->exists($path));
    }

    public function test_an_aborted_upload_leaves_nothing(): void
    {
        $settings = $this->settings();
        $path = 'vanilla/it-'.bin2hex(random_bytes(4)).'-save.zip';
        $upload = $this->transfers()->startUpload($settings, $path, 900, 5 * 1024 * 1024, 2);
        Http::withBody(random_bytes(1000), 'application/octet-stream')->put($upload->partUrls[0])->throw();

        $this->assertNull($this->transfers()->abort($settings, $upload));
        $this->assertFalse($this->storage()->disk($settings)->exists($path));

        // A link for an aborted upload no longer takes parts.
        $this->assertFalse(Http::withBody('x', 'application/octet-stream')->put($upload->partUrls[1])->successful());
    }

    /**
     * Writes the files a VPS would get for one start and one stop, with real links (#5347).
     */
    public function test_writes_the_vps_files(): void
    {
        $dir = getenv('MVM_S3_SCRIPT_DIR');

        if (! is_string($dir) || $dir === '') {
            $this->markTestSkipped('No MVM_S3_SCRIPT_DIR.');
        }

        $settings = $this->settings('worlds');
        $server = new Server;
        $server->slug = 'vanilla';

        // The world a start downloads: a zip holding one folder, as server packs come.
        $zipFile = tempnam(sys_get_temp_dir(), 'mvm-it');
        $zip = new ZipArchive;
        $zip->open($zipFile, ZipArchive::OVERWRITE);
        $zip->addFromString('pack/server.properties', "motd=from s3\n");
        $zip->addFromString('pack/world/level.dat', random_bytes(2048));
        $zip->close();
        $zipBytes = (string) file_get_contents($zipFile);
        unlink($zipFile);

        $archive = new WorldArchive;
        $archive->path = 'vanilla/20261122-210000-abcdef-upload.zip';
        $archive->size = strlen($zipBytes);
        $archive->sha256 = hash('sha256', $zipBytes);
        $this->storage()->disk($settings)->put($archive->path, $zipBytes);

        $savePath = 'vanilla/20261122-220000-'.bin2hex(random_bytes(3)).'-save.zip';
        $upload = $this->transfers()->startUpload($settings, $savePath, 1800, 5 * 1024 * 1024, 6);
        $tooSmall = $this->transfers()->startUpload($settings, 'vanilla/20261122-230000-aaaaaa-save.zip', 1800, 5 * 1024 * 1024, 1);

        $sets = [
            'prepare' => WorldSync::prepareFiles($server, $archive, $settings, $this->transfers()->downloadUrl($settings, $archive->path, 1800)),
            'save' => WorldSync::saveFiles($server, $settings, $upload),
            'save-too-big' => WorldSync::saveFiles($server, $settings, $tooSmall),
            'bad-sum' => WorldSync::prepareFiles($server, tap(clone $archive, fn (WorldArchive $a) => $a->sha256 = str_repeat('0', 64)), $settings,
                $this->transfers()->downloadUrl($settings, $archive->path, 1800)),
        ];

        foreach ($sets as $name => $files) {
            foreach ($files as $remote => [$contents]) {
                @mkdir("{$dir}/{$name}", 0777, true);
                file_put_contents("{$dir}/{$name}/".basename($remote), $contents);
            }
        }

        file_put_contents("{$dir}/save-path", $savePath);
        file_put_contents("{$dir}/upload.json", json_encode(['path' => $savePath, 'upload_id' => $upload->uploadId], JSON_THROW_ON_ERROR));
        $this->assertFileExists("{$dir}/save/parts");
    }

    /**
     * After the container ran save.sh: the object is there with the size and hash it printed.
     */
    public function test_checks_what_the_vps_saved(): void
    {
        $result = getenv('MVM_S3_SCRIPT_CHECK');

        if (! is_string($result) || $result === '') {
            $this->markTestSkipped('No MVM_S3_SCRIPT_CHECK.');
        }

        $saved = WorldSync::parseSaveOutput((string) file_get_contents($result));
        $this->assertNotNull($saved);
        $path = trim((string) file_get_contents(dirname($result).'/save-path'));
        $disk = $this->storage()->disk($this->settings('worlds'));

        $this->assertSame($saved['size'], $disk->size($path));
        $this->assertSame($saved['sha256'], hash('sha256', (string) $disk->get($path)));
        $this->assertGreaterThan(10 * 1024 * 1024, $saved['size'], 'Big enough for three parts.');
    }
}
