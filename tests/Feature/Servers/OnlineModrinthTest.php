<?php

namespace Tests\Feature\Servers;

use App\Minecraft\FakeVersions;
use App\Minecraft\Modrinth\ModLoaders;
use App\Minecraft\Modrinth\ModrinthException;
use App\Minecraft\Modrinth\OnlineModrinth;
use App\Minecraft\Software;
use App\Minecraft\VersionSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * The real Modrinth client (#5352) against faked answers shaped like the live API (fetched
 * Nov 23, 2026), with a real .mrpack built here. Minecraft and Fabric come from FakeVersions.
 * No real HTTP.
 */
class OnlineModrinthTest extends TestCase
{
    private const NEOFORGE_SHA256 = '4f312ec39ff935c661f4053ef398056a8f946057b04be1c1eebf0518a18b2ba2';

    private string $mrpack = '';

    protected function tearDown(): void
    {
        if ($this->mrpack !== '' && is_file($this->mrpack)) {
            unlink($this->mrpack);
        }

        parent::tearDown();
    }

    private function client(): OnlineModrinth
    {
        return new OnlineModrinth(new ModLoaders($this->app->make(VersionSource::class)));
    }

    /**
     * @param  array<string, mixed>  $index
     * @param  array<string, string>  $extra  other zip entries
     */
    private function buildMrpack(array $index, array $extra = []): string
    {
        $this->mrpack = tempnam(sys_get_temp_dir(), 'mrpack-test-') ?: '';
        $zip = new ZipArchive;
        $zip->open($this->mrpack, ZipArchive::OVERWRITE);
        $zip->addFromString('modrinth.index.json', (string) json_encode($index));

        foreach ($extra as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return (string) file_get_contents($this->mrpack);
    }

    /**
     * @param  array<string, mixed>  $dependencies
     * @param  list<array<string, mixed>>  $files
     * @return array<string, mixed>
     */
    private function index(array $dependencies, array $files): array
    {
        return ['formatVersion' => 1, 'game' => 'minecraft', 'versionId' => '1.0.0', 'name' => 'Test Pack', 'files' => $files, 'dependencies' => $dependencies];
    }

    /**
     * @return array<string, mixed>
     */
    private function file(string $path, string $url, ?string $server = 'required'): array
    {
        return [
            'path' => $path,
            'hashes' => ['sha1' => str_repeat('a', 40), 'sha512' => str_repeat('D', 128)],
            'env' => $server === null ? null : ['client' => 'required', 'server' => $server],
            'downloads' => [$url],
            'fileSize' => 1234,
        ];
    }

    /** @var array<string, mixed> url pattern => response, swapped between cases */
    private array $routes = [];

    private bool $faked = false;

    /**
     * @param  array<string, mixed>  $routes
     */
    private function route(array $routes): void
    {
        // One callback for the whole test: Http::fake() stubs pile up and the first match wins.
        $this->routes = $routes;
        Cache::flush();

        if (! $this->faked) {
            $this->faked = true;
            Http::fake(function (Request $request) {
                foreach ($this->routes as $pattern => $response) {
                    if (Str::is($pattern, preg_replace('#^https://#', '', $request->url()) ?? '')) {
                        return $response;
                    }
                }

                return Http::response('not faked', 500);
            });
        }
    }

    private function fakeVersion(string $bytes, string $loader = 'fabric', string $game = '1.21.11'): void
    {
        $this->route([
            'api.modrinth.com/v2/version/Vv1Vv1Vv' => Http::response([
                'id' => 'Vv1Vv1Vv', 'project_id' => 'Pp1Pp1Pp', 'name' => 'Test Pack 1.0.0', 'version_number' => '1.0.0',
                'game_versions' => [$game], 'loaders' => [$loader], 'version_type' => 'release',
                'files' => [
                    ['url' => 'https://cdn.modrinth.com/data/Pp1Pp1Pp/versions/Vv1Vv1Vv/cosign-bundle.zip', 'primary' => false, 'hashes' => ['sha512' => str_repeat('0', 128)], 'size' => 7339],
                    ['url' => 'https://cdn.modrinth.com/data/Pp1Pp1Pp/versions/Vv1Vv1Vv/Test%20Pack%201.0.0%2B1.21.11.mrpack', 'primary' => true, 'hashes' => ['sha512' => hash('sha512', $bytes)], 'size' => strlen($bytes)],
                ],
            ]),
            'cdn.modrinth.com/data/Pp1Pp1Pp/versions/Vv1Vv1Vv/Test%20Pack%201.0.0%2B1.21.11.mrpack' => Http::response($bytes),
            'maven.neoforged.net/releases/net/neoforged/neoforge/21.1.266/neoforge-21.1.266-installer.jar.sha256' => Http::response(self::NEOFORGE_SHA256),
        ]);
    }

    public function test_search_asks_for_server_modpacks_and_keeps_what_the_form_needs(): void
    {
        Http::fake(['api.modrinth.com/v2/search*' => Http::response(['hits' => [
            ['project_id' => 'Pp1Pp1Pp', 'slug' => 'test-pack', 'title' => 'Test Pack', 'description' => 'A pack.', 'icon_url' => 'https://cdn.modrinth.com/data/x/icon.webp', 'downloads' => 42, 'server_side' => 'required'],
            ['project_id' => 'bad id!', 'title' => 'Broken'],
            ['project_id' => 'Qq1Qq1Qq', 'title' => 'No icon', 'icon_url' => 'http://example.com/a.png'],
        ], 'total_hits' => 3])]);

        $hits = $this->client()->search('  test ');

        $this->assertSame([
            ['id' => 'Pp1Pp1Pp', 'slug' => 'test-pack', 'title' => 'Test Pack', 'description' => 'A pack.', 'icon_url' => 'https://cdn.modrinth.com/data/x/icon.webp', 'downloads' => 42],
            ['id' => 'Qq1Qq1Qq', 'slug' => 'Qq1Qq1Qq', 'title' => 'No icon', 'description' => '', 'icon_url' => null, 'downloads' => 0],
        ], $hits);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['query'] === 'test'
                && json_decode((string) $query['facets'], true) === [['project_type:modpack'], ['server_side:required', 'server_side:optional']]
                && str_starts_with($request->header('User-Agent')[0], 'minecraft-vps-manager');
        });
    }

    public function test_versions_leave_out_ones_without_an_mrpack_or_a_known_loader(): void
    {
        Http::fake(['api.modrinth.com/v2/project/Pp1Pp1Pp/version*' => Http::response([
            ['id' => 'Vv2Vv2Vv', 'project_id' => 'Pp1Pp1Pp', 'name' => 'Two', 'version_number' => '2.0', 'game_versions' => ['26.3'], 'loaders' => ['neoforge'], 'files' => [['url' => 'https://cdn.modrinth.com/a/two.mrpack', 'primary' => true, 'hashes' => ['sha512' => str_repeat('1', 128)]]]],
            ['id' => 'Vv1Vv1Vv', 'project_id' => 'Pp1Pp1Pp', 'name' => 'Liteloader', 'version_number' => '1.5', 'game_versions' => ['1.21.11'], 'loaders' => ['liteloader'], 'files' => [['url' => 'https://cdn.modrinth.com/a/x.mrpack', 'primary' => true, 'hashes' => ['sha512' => str_repeat('1', 128)]]]],
            ['id' => 'Vv0Vv0Vv', 'project_id' => 'Pp1Pp1Pp', 'name' => 'Zip only', 'version_number' => '1.0', 'game_versions' => ['1.21.11'], 'loaders' => ['fabric'], 'files' => [['url' => 'https://cdn.modrinth.com/a/x.zip', 'primary' => true, 'hashes' => ['sha512' => str_repeat('1', 128)]]]],
        ])]);

        $versions = $this->client()->versions('Pp1Pp1Pp');

        $this->assertCount(1, $versions);
        $this->assertSame(['id' => 'Vv2Vv2Vv', 'name' => 'Two', 'version_number' => '2.0', 'minecraft_version' => '26.3', 'loader' => 'neoforge'], $versions[0]->toArray());
    }

    public function test_a_fabric_pack_becomes_files_with_hashes_and_the_fabric_install(): void
    {
        $bytes = $this->buildMrpack($this->index(['minecraft' => '1.21.11', 'fabric-loader' => '0.16.14'], [
            $this->file('mods/lithium.jar', 'https://cdn.modrinth.com/data/gvQqBUqZ/versions/abc/lithium.jar'),
            $this->file('mods/iris.jar', 'https://cdn.modrinth.com/data/YL57xq9U/versions/def/iris.jar', 'unsupported'),
            $this->file('config/My Pack/a.json', 'https://raw.githubusercontent.com/me/pack/main/a.json', null),
            // From a real pack (Vanilla Perfected, Nov 23): an apostrophe is only data on the VPS.
            $this->file("resourcepacks/qrafty's-capitalized-font-3.5.zip", 'https://cdn.modrinth.com/data/x/versions/y/qrafty%27s-capitalized-font-3.5.zip'),
        ]), ['overrides/config/x.toml' => 'x=1', 'server-overrides/server.properties' => 'motd=hi']);
        $this->fakeVersion($bytes);

        $plan = $this->client()->plan('Vv1Vv1Vv', 'Test Pack');

        $this->assertSame(Software::Modrinth, $plan->software);
        $this->assertSame('Test Pack (Fabric 0.16.14, Minecraft 1.21.11)', $plan->description);
        $this->assertSame(hash('sha512', $bytes), $plan->mrpack?->hash);
        $this->assertStringContainsString('Test%20Pack%201.0.0%2B1.21.11.mrpack', $plan->mrpack->url, "Modrinth's links are percent-encoded.");
        $this->assertSame(['mods/lithium.jar', 'config/My Pack/a.json', "resourcepacks/qrafty's-capitalized-font-3.5.zip"], array_map(fn ($f) => $f->path, $plan->files), 'Client-only files are skipped.');
        $this->assertSame(str_repeat('d', 128), $plan->files[0]->sha512, 'Hashes are lowercased.');
        $this->assertSame('fabric', $plan->loader?->loader);
        $this->assertSame('0.16.14', $plan->loader->loaderVersion, "The pack's own loader version, not the newest.");
        $this->assertSame(['server.jar', 'fabric-installer.jar'], array_map(fn ($d) => $d->file, $plan->loader->downloads));
        $this->assertSame(FakeVersions::SHA1, $plan->loader->downloads[0]->hash);
    }

    public function test_a_neoforge_pack_gets_the_installer_and_its_maven_sha256(): void
    {
        $bytes = $this->buildMrpack($this->index(['minecraft' => '1.21.11', 'neoforge' => '21.1.266'], []));
        $this->fakeVersion($bytes, 'neoforge');

        $plan = $this->client()->plan('Vv1Vv1Vv', 'All the Mods 10 & more');

        $this->assertSame('All the Mods 10  more (NeoForge 21.1.266, Minecraft 1.21.11)', $plan->description);
        $installer = $plan->loader?->installer();
        $this->assertSame('https://maven.neoforged.net/releases/net/neoforged/neoforge/21.1.266/neoforge-21.1.266-installer.jar', $installer?->url);
        $this->assertSame(self::NEOFORGE_SHA256, $installer->hash);
        $this->assertCount(1, $plan->loader->downloads, 'NeoForge fetches Minecraft itself.');
    }

    public function test_a_file_outside_the_folder_or_on_another_host_refuses_the_pack(): void
    {
        foreach ([
            $this->file('../../etc/cron.d/x', 'https://cdn.modrinth.com/a/x.jar'),
            $this->file('/root/.ssh/authorized_keys', 'https://cdn.modrinth.com/a/x.jar'),
            $this->file('mods\\a.jar', 'https://cdn.modrinth.com/a/x.jar'),
            $this->file("mods/a\nb.jar", 'https://cdn.modrinth.com/a/x.jar'),
            $this->file('mods/./../a.jar', 'https://cdn.modrinth.com/a/x.jar'),
            $this->file('mods/a.jar', 'https://evil.example/x.jar'),
            $this->file('mods/a.jar', 'http://cdn.modrinth.com/x.jar'),
        ] as $bad) {
            $bytes = $this->buildMrpack($this->index(['minecraft' => '1.21.11', 'fabric-loader' => '0.16.14'], [$bad]));
            $this->fakeVersion($bytes);

            try {
                $this->client()->plan('Vv1Vv1Vv', 'Bad');
                $this->fail("Accepted {$bad['path']} from {$bad['downloads'][0]}");
            } catch (ModrinthException $e) {
                $this->assertTrue($e->permanent);
                $this->assertStringStartsWith("The pack can't be installed:", $e->getMessage());
            }
        }
    }

    public function test_a_wrong_hash_an_unsafe_zip_or_an_unknown_loader_is_refused(): void
    {
        $bytes = $this->buildMrpack($this->index(['minecraft' => '1.21.11', 'fabric-loader' => '0.16.14'], []));
        $this->fakeVersion($bytes);
        $this->routes['cdn.modrinth.com/data/Pp1Pp1Pp/versions/Vv1Vv1Vv/Test%20Pack%201.0.0%2B1.21.11.mrpack'] = Http::response($bytes.'x');

        try {
            $this->client()->plan('Vv1Vv1Vv', 'X');
            $this->fail('Accepted a changed .mrpack.');
        } catch (ModrinthException $e) {
            $this->assertSame("The downloaded .mrpack doesn't match the SHA-512 Modrinth lists.", $e->getMessage());
        }

        $this->buildMrpack($this->index(['minecraft' => '1.21.11', 'fabric-loader' => '0.16.14'], []), ['../escape.txt' => 'x']);

        try {
            OnlineModrinth::readIndex($this->mrpack);
            $this->fail('Read an unsafe zip.');
        } catch (ModrinthException $e) {
            $this->assertTrue($e->permanent);
            $this->assertStringContainsString("isn't safe to unpack", $e->getMessage());
        }

        $bytes = $this->buildMrpack($this->index(['minecraft' => '1.21.11', 'liteloader' => '1'], []));
        $this->fakeVersion($bytes);

        $this->expectExceptionMessage("The pack needs a mod loader this app can't install");
        $this->client()->plan('Vv1Vv1Vv', 'X');
    }

    public function test_a_minecraft_version_before_1_17_is_refused(): void
    {
        $bytes = $this->buildMrpack($this->index(['minecraft' => '1.12.2', 'forge' => '14.23.5.2860'], []));
        $this->fakeVersion($bytes, 'forge', '1.12.2');

        $this->expectExceptionMessage("Minecraft 1.12.2 isn't one this app installs (1.17 on).");
        $this->client()->plan('Vv1Vv1Vv', 'Old');
    }

    public function test_modrinth_being_down_can_be_tried_again(): void
    {
        $this->route(['api.modrinth.com/*' => Http::response('', 503)]);

        try {
            $this->client()->version('Vv1Vv1Vv');
            $this->fail('No error.');
        } catch (ModrinthException $e) {
            $this->assertFalse($e->permanent);
            $this->assertStringStartsWith("Couldn't reach Modrinth:", $e->getMessage());
        }

        $this->route(['api.modrinth.com/v2/version/Zz1Zz1Zz' => Http::response(['error' => 'not_found'], 404)]);

        try {
            $this->client()->version('Zz1Zz1Zz');
            $this->fail('No error.');
        } catch (ModrinthException $e) {
            $this->assertTrue($e->permanent);
        }
    }
}
