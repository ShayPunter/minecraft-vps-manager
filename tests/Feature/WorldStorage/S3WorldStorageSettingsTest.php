<?php

namespace Tests\Feature\WorldStorage;

use App\Models\User;
use App\Models\WorldStorageSetting;
use App\WorldStorage\WorldStorage;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Admin → World storage, S3-compatible type (#5347). In testing the disk is a local folder,
 * so these check the form, validation and what is stored; S3IntegrationTest talks to a real
 * S3 mock.
 */
class S3WorldStorageSettingsTest extends WorldStorageTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'driver' => 's3',
            'endpoint' => 'https://fsn1.your-objectstorage.com/',
            'region' => 'FSN1',
            'bucket' => 'mvm-worlds',
            'username' => 'AKIAEXAMPLE12345678',
            'password' => 'secret/key+with=odd chars',
            'root' => 'minecraft-worlds',
            'path_style' => false,
            ...$overrides,
        ];
    }

    public function test_saving_s3_stores_the_bucket_and_encrypts_the_secret_key(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form())
            ->assertRedirect('/admin/world-storage')
            ->assertSessionHasNoErrors();

        $row = WorldStorageSetting::query()->sole();
        $this->assertSame('s3', $row->driver);
        $this->assertSame('https://fsn1.your-objectstorage.com', $row->endpoint, 'Trailing slash trimmed.');
        $this->assertSame('fsn1', $row->region, 'Region lowercased.');
        $this->assertSame('mvm-worlds', $row->bucket);
        $this->assertFalse($row->path_style);
        $this->assertSame('fsn1.your-objectstorage.com', $row->host);
        $this->assertSame(443, $row->port);
        $this->assertSame('AKIAEXAMPLE12345678', $row->username);
        $this->assertSame('secret/key+with=odd chars', $row->password);
        $this->assertNull($row->host_key);

        $raw = DB::table('world_storage_settings')->value('password');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('secret/key', $raw);

        $settings = app(WorldStorage::class)->settings();
        $this->assertTrue($settings->isS3());
        $this->assertSame('minecraft-worlds/vanilla/a.zip', $settings->objectKey('vanilla/a.zip'));
    }

    public function test_the_page_shows_s3_fields_but_never_the_secret_key(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put('/admin/world-storage', $this->form(['path_style' => true, 'endpoint' => 'http://minio.internal:9000']));

        $response = $this->actingAs($admin)->get('/admin/world-storage');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('admin/world-storage/Edit')
            ->where('storage.driver', 's3')
            ->where('storage.endpoint', 'http://minio.internal:9000')
            ->where('storage.bucket', 'mvm-worlds')
            ->where('storage.region', 'fsn1')
            ->where('storage.path_style', true)
            ->where('storage.username', 'AKIAEXAMPLE12345678')
            ->where('storage.has_password', true)
            ->missing('storage.password')
            ->where('problem', null));

        $this->assertStringNotContainsString('secret/key', (string) $response->getContent());
    }

    public function test_an_empty_prefix_and_no_endpoint_mean_the_top_of_an_aws_bucket(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form(['endpoint' => '', 'root' => '', 'region' => 'eu-central-1']))
            ->assertSessionHasNoErrors();

        $row = WorldStorageSetting::query()->sole();
        $this->assertNull($row->endpoint);
        $this->assertSame('', $row->root);
        $this->assertSame('s3.eu-central-1.amazonaws.com', $row->host);
        $this->assertSame('vanilla/a.zip', app(WorldStorage::class)->settings()->objectKey('vanilla/a.zip'));
    }

    public function test_the_secret_key_is_kept_when_left_empty_and_required_otherwise(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form(['password' => '']))
            ->assertSessionHasErrors(['password' => 'S3 needs the secret key.']);
        $this->assertSame(0, WorldStorageSetting::query()->count());

        $this->actingAs($admin)->put('/admin/world-storage', $this->form());
        $this->actingAs($admin)->put('/admin/world-storage', $this->form(['password' => '', 'bucket' => 'other-bucket']))
            ->assertSessionHasNoErrors();

        $row = WorldStorageSetting::query()->sole();
        $this->assertSame('other-bucket', $row->bucket);
        $this->assertSame('secret/key+with=odd chars', $row->password);
    }

    public function test_a_refused_key_saves_nothing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form(['password' => WorldStorage::FAKE_WRONG_PASSWORD]))
            ->assertSessionHasErrors(['connection']);

        $this->assertStringContainsString('Couldn\'t write to bucket "mvm-worlds" under "minecraft-worlds/" on fsn1.your-objectstorage.com: ',
            (string) session('errors')->first('connection'));
        $this->assertStringContainsString('SignatureDoesNotMatch', (string) session('errors')->first('connection'));
        $this->assertSame(0, WorldStorageSetting::query()->count());
    }

    public function test_switching_from_s3_to_sftp_clears_the_s3_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put('/admin/world-storage', $this->form());

        $this->actingAs($admin)->put('/admin/world-storage', [
            'driver' => 'sftp', 'host' => 'u1.your-storagebox.de', 'port' => 23, 'username' => 'u1',
            'password' => 'pw', 'root' => 'worlds', 'ftps' => true,
        ])->assertSessionHasNoErrors();

        $row = WorldStorageSetting::query()->sole();
        $this->assertSame('sftp', $row->driver);
        $this->assertNull($row->bucket);
        $this->assertNull($row->endpoint);
        $this->assertNull($row->region);
        $this->assertSame(WorldStorage::FAKE_HOST_KEY, $row->host_key);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badValues(): array
    {
        return [
            'endpoint with a path' => [['endpoint' => 'https://s3.example.com/bucket'], 'endpoint'],
            'endpoint with a query' => [['endpoint' => 'https://s3.example.com?x=1'], 'endpoint'],
            'endpoint not http' => [['endpoint' => 'ftp://s3.example.com'], 'endpoint'],
            'endpoint with a quote' => [['endpoint' => 'https://s3.example.com"'], 'endpoint'],
            'bucket uppercase' => [['bucket' => 'MyBucket'], 'bucket'],
            'bucket too short' => [['bucket' => 'ab'], 'bucket'],
            'bucket with ..' => [['bucket' => 'a..b'], 'bucket'],
            'bucket with a slash' => [['bucket' => 'a/b'], 'bucket'],
            'no region' => [['region' => ''], 'region'],
            'region with a space' => [['region' => 'eu central'], 'region'],
            'prefix with ..' => [['root' => 'worlds/../x'], 'root'],
            'access key with a quote' => [['username' => "AKIA'x"], 'username'],
            'private key' => [['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----"], 'private_key'],
        ];
    }

    #[DataProvider('badValues')]
    public function test_values_that_would_reach_a_link_or_script_are_validated(array $overrides, string $field): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/world-storage', $this->form($overrides))->assertSessionHasErrors([$field]);
        $this->assertSame(0, WorldStorageSetting::query()->count());
    }

    public function test_the_env_can_set_up_s3(): void
    {
        config(['world_storage.env' => [
            ...config('world_storage.env'),
            'driver' => 's3', 'bucket' => 'env-bucket', 'region' => 'auto', 'username' => 'envkey',
            'password' => 'envsecret', 'endpoint' => 'https://abc.r2.cloudflarestorage.com', 'root' => 'worlds',
        ]]);

        $settings = app(WorldStorage::class)->settings();

        $this->assertTrue($settings->isS3());
        $this->assertSame('env-bucket', $settings->bucket);
        $this->assertSame('abc.r2.cloudflarestorage.com', $settings->host);
        $this->assertSame('env', app(WorldStorage::class)->source());
    }
}
