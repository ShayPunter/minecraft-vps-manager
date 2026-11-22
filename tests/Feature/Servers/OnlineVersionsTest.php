<?php

namespace Tests\Feature\Servers;

use App\Minecraft\OnlineVersions;
use App\Minecraft\Software;
use App\Minecraft\VersionSourceException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The real version sources (#5351), against faked Mojang, PaperMC and Fabric answers shaped
 * like the real ones (fetched Nov 22, 2026). No real HTTP.
 */
class OnlineVersionsTest extends TestCase
{
    private const SERVER_SHA1 = '33680f5f2ac32864d6d7cf5e56a705fdb3e05f4c';

    private const PAPER_SHA256 = 'c5df5fb9f6155e48380c67c6e2b2dc2581b7db1a4b665d2c3818057b7efb6692';

    private const INSTALLER_SHA256 = '61e035bf7bf70153e127440ce34de47c9036f0a2d0c65d1529454bd35ceefe4f';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeSources(array $overrides = []): void
    {
        $version = fn (string $id, int $java, string $sha1) => [
            'id' => $id,
            'javaVersion' => ['component' => 'java-runtime', 'majorVersion' => $java],
            'downloads' => ['server' => ['sha1' => $sha1, 'size' => 62294556, 'url' => "https://piston-data.mojang.com/v1/objects/{$sha1}/server.jar"]],
        ];

        Http::fake([...[
            'piston-meta.mojang.com/mc/game/version_manifest_v2.json' => Http::response([
                'latest' => ['release' => '26.3', 'snapshot' => '26.4-snapshot-10'],
                'versions' => [
                    ['id' => '26.4-snapshot-10', 'type' => 'snapshot', 'url' => 'https://piston-meta.mojang.com/v1/packages/s/26.4-snapshot-10.json'],
                    ['id' => '26.3', 'type' => 'release', 'url' => 'https://piston-meta.mojang.com/v1/packages/a/26.3.json'],
                    ['id' => '1.21.11', 'type' => 'release', 'url' => 'https://piston-meta.mojang.com/v1/packages/b/1.21.11.json'],
                    ['id' => '1.17', 'type' => 'release', 'url' => 'https://piston-meta.mojang.com/v1/packages/c/1.17.json'],
                    ['id' => '1.16.5', 'type' => 'release', 'url' => 'https://piston-meta.mojang.com/v1/packages/d/1.16.5.json'],
                ],
            ]),
            'piston-meta.mojang.com/v1/packages/a/26.3.json' => Http::response($version('26.3', 25, self::SERVER_SHA1)),
            'piston-meta.mojang.com/v1/packages/b/1.21.11.json' => Http::response($version('1.21.11', 21, str_repeat('1', 40))),
            'fill.papermc.io/v3/projects/paper/versions' => Http::response(['versions' => [
                ['version' => ['id' => '26.3', 'java' => ['version' => ['minimum' => 25]]]],
                ['version' => ['id' => '26.3-rc-3', 'java' => ['version' => ['minimum' => 25]]]],
                ['version' => ['id' => '1.21.11', 'java' => ['version' => ['minimum' => 21]]]],
                ['version' => ['id' => '1.16.5', 'java' => ['version' => ['minimum' => 8]]]],
            ]]),
            'fill.papermc.io/v3/projects/paper/versions/26.3/builds' => Http::response([
                ['id' => 259, 'channel' => 'BETA', 'downloads' => ['server:default' => ['url' => 'https://fill-data.papermc.io/v1/objects/x/paper-26.3-259.jar', 'checksums' => ['sha256' => str_repeat('9', 64)]]]],
                ['id' => 258, 'channel' => 'STABLE', 'downloads' => ['server:default' => ['name' => 'paper-26.3-258.jar', 'url' => 'https://fill-data.papermc.io/v1/objects/'.self::PAPER_SHA256.'/paper-26.3-258.jar', 'checksums' => ['sha256' => self::PAPER_SHA256]]]],
            ]),
            'fill.papermc.io/v3/projects/paper/versions/1.21.11/builds' => Http::response([
                ['id' => 3, 'channel' => 'ALPHA', 'downloads' => ['server:default' => ['url' => 'https://fill-data.papermc.io/v1/objects/y/paper.jar', 'checksums' => ['sha256' => str_repeat('8', 64)]]]],
            ]),
            'meta.fabricmc.net/v2/versions/game' => Http::response([
                ['version' => '26.4-snapshot-10', 'stable' => false],
                ['version' => '26.3', 'stable' => true],
                ['version' => '1.21.11', 'stable' => true],
                ['version' => '1.16.5', 'stable' => true],
            ]),
            'meta.fabricmc.net/v2/versions/loader' => Http::response([
                ['version' => '0.20.2-beta', 'stable' => false],
                ['version' => '0.20.1', 'stable' => true],
            ]),
            'meta.fabricmc.net/v2/versions/installer' => Http::response([
                ['url' => 'https://maven.fabricmc.net/net/fabricmc/fabric-installer/1.1.2/fabric-installer-1.1.2.jar', 'version' => '1.1.2', 'stable' => true],
            ]),
            'maven.fabricmc.net/net/fabricmc/fabric-installer/1.1.2/fabric-installer-1.1.2.jar.sha256' => Http::response(self::INSTALLER_SHA256),
        ], ...$overrides]);
    }

    public function test_vanilla_lists_releases_down_to_1_17_and_installs_the_mojang_jar(): void
    {
        $this->fakeSources();
        $source = new OnlineVersions;

        $this->assertSame(['26.3', '1.21.11', '1.17'], $source->versions(Software::Vanilla));
        $this->assertSame(25, $source->javaFor(Software::Vanilla, '26.3'));

        $plan = $source->plan(Software::Vanilla, '26.3');
        $this->assertSame('Vanilla 26.3', $plan->description);
        $this->assertCount(1, $plan->downloads);
        $this->assertSame('https://piston-data.mojang.com/v1/objects/'.self::SERVER_SHA1.'/server.jar', $plan->downloads[0]->url);
        $this->assertSame(['server.jar', 'sha1', self::SERVER_SHA1], [$plan->downloads[0]->file, $plan->downloads[0]->algorithm, $plan->downloads[0]->hash]);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->header('User-Agent')[0] ?? '', 'minecraft-vps-manager'));
    }

    public function test_paper_lists_release_versions_and_installs_the_newest_stable_build(): void
    {
        $this->fakeSources();
        $source = new OnlineVersions;

        $this->assertSame(['26.3', '1.21.11'], $source->versions(Software::Paper));
        $this->assertSame(21, $source->javaFor(Software::Paper, '1.21.11'));

        $plan = $source->plan(Software::Paper, '26.3');
        $this->assertSame('Paper 26.3 (build 258)', $plan->description);
        $this->assertSame(['sha256', self::PAPER_SHA256], [$plan->downloads[0]->algorithm, $plan->downloads[0]->hash]);
    }

    public function test_paper_without_a_stable_build_says_so(): void
    {
        $this->fakeSources();

        $this->expectException(VersionSourceException::class);
        $this->expectExceptionMessage('Paper has no stable build for 1.21.11 yet.');

        (new OnlineVersions)->plan(Software::Paper, '1.21.11');
    }

    public function test_fabric_installs_its_installer_with_mavens_hash_on_top_of_the_vanilla_jar(): void
    {
        $this->fakeSources();
        $source = new OnlineVersions;

        $this->assertSame(['26.3', '1.21.11'], $source->versions(Software::Fabric), '1.16.5 is below the 1.17 cut.');

        $plan = $source->plan(Software::Fabric, '26.3');
        $this->assertSame('0.20.1', $plan->fabricLoader, 'The newest stable loader, not the beta.');
        $this->assertSame('Fabric 26.3 (loader 0.20.1, installer 1.1.2)', $plan->description);
        $this->assertSame(['server.jar', 'fabric-installer.jar'], array_map(fn ($d) => $d->file, $plan->downloads));
        $this->assertSame(self::INSTALLER_SHA256, $plan->installer()?->hash);
        $this->assertFalse($plan->installer()->intoServerFolder);
    }

    public function test_an_unlisted_version_is_refused(): void
    {
        $this->fakeSources();

        $this->expectException(VersionSourceException::class);
        $this->expectExceptionMessage('Vanilla has no release 1.16.5.');

        (new OnlineVersions)->plan(Software::Vanilla, '1.16.5');
    }

    public function test_a_source_that_is_down_is_a_version_source_error(): void
    {
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        $this->expectException(VersionSourceException::class);
        $this->expectExceptionMessage("Couldn't reach Mojang's version list");

        (new OnlineVersions)->versions(Software::Vanilla);
    }

    public function test_a_download_from_somewhere_else_is_refused(): void
    {
        $this->fakeSources([
            'fill.papermc.io/v3/projects/paper/versions/26.3/builds' => Http::response([
                ['id' => 1, 'channel' => 'STABLE', 'downloads' => ['server:default' => ['url' => 'https://evil.example/paper.jar', 'checksums' => ['sha256' => self::PAPER_SHA256]]]],
            ]),
        ]);

        $this->expectException(VersionSourceException::class);
        $this->expectExceptionMessage('The Paper download for 26.3 looks wrong');

        (new OnlineVersions)->plan(Software::Paper, '26.3');
    }
}
