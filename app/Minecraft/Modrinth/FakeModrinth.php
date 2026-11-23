<?php

namespace App\Minecraft\Modrinth;

use App\Minecraft\Download;
use App\Minecraft\FakeVersions;
use App\Minecraft\Packs\LoaderInstall;
use App\Minecraft\Packs\PackFile;
use App\Minecraft\Packs\PackPlan;
use App\Minecraft\Software;
use Illuminate\Contracts\Cache\Repository;

/**
 * Modrinth as tests and E2E see it (#5352). Never calls anything; failWith() lives in the
 * cache, so it survives artisan serve's separate processes.
 *
 *   Cobble Pack (AAAAAAAA): Fabric, versions 2.0.0 (26.3) and 1.0.0 (1.21.11)
 *   Forge Frontier (BBBBBBBB): NeoForge, version 5.1 (1.21.11)
 */
class FakeModrinth implements ModrinthSource
{
    public const SHA512 = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private const FAIL_KEY = 'mvm:fake-modrinth:fail';

    private const PROJECTS = [
        'AAAAAAAA' => ['slug' => 'cobble-pack', 'title' => 'Cobble Pack', 'description' => 'A small Fabric pack for friends.', 'downloads' => 1200],
        'BBBBBBBB' => ['slug' => 'forge-frontier', 'title' => 'Forge Frontier', 'description' => 'Tech and magic on NeoForge.', 'downloads' => 98000],
    ];

    /** version id => [project, name, number, minecraft, loader, loader version] */
    private const VERSIONS = [
        'AAAAAAA2' => ['AAAAAAAA', 'Cobble Pack 2.0.0', '2.0.0', '26.3', 'fabric', '0.20.1'],
        'AAAAAAA1' => ['AAAAAAAA', 'Cobble Pack 1.0.0', '1.0.0', '1.21.11', 'fabric', '0.20.1'],
        'BBBBBBB5' => ['BBBBBBBB', 'Forge Frontier 5.1', '5.1', '1.21.11', 'neoforge', '21.11.4'],
    ];

    public function __construct(private readonly Repository $cache) {}

    /** Every call fails with this message until reset(); $permanent as a broken pack would. */
    public function failWith(string $message, bool $permanent = false): void
    {
        $this->cache->forever(self::FAIL_KEY, [$message, $permanent]);
    }

    public function reset(): void
    {
        $this->cache->forget(self::FAIL_KEY);
    }

    public function search(string $query): array
    {
        $this->guard();
        $list = [];

        foreach (self::PROJECTS as $id => $project) {
            if (trim($query) === '' || str_contains(strtolower($project['title']), strtolower(trim($query)))) {
                $list[] = ['id' => $id, ...$project, 'icon_url' => null];
            }
        }

        return $list;
    }

    public function title(string $projectId): string
    {
        $this->guard();

        return self::PROJECTS[$projectId]['title'] ?? throw new ModrinthException('Modrinth has no such modpack or version.', permanent: true);
    }

    public function versions(string $projectId): array
    {
        $this->guard();

        if (! isset(self::PROJECTS[$projectId])) {
            throw new ModrinthException('Modrinth has no such modpack or version.', permanent: true);
        }

        return array_values(array_filter(
            array_map(fn (string $id): ModrinthVersion => $this->version($id), array_keys(self::VERSIONS)),
            fn (ModrinthVersion $v): bool => $v->projectId === $projectId,
        ));
    }

    public function version(string $versionId): ModrinthVersion
    {
        $this->guard();
        $v = self::VERSIONS[$versionId] ?? throw new ModrinthException('Modrinth has no such modpack or version.', permanent: true);

        return new ModrinthVersion($versionId, $v[0], $v[1], $v[2], $v[3], $v[4], "https://cdn.modrinth.com/data/{$v[0]}/versions/{$versionId}/pack.mrpack", self::SHA512, 4096);
    }

    public function plan(string $versionId, string $title): PackPlan
    {
        $version = $this->version($versionId);
        [, , , $minecraft, $loader, $loaderVersion] = self::VERSIONS[$versionId];

        $vanilla = new Download('https://piston-data.mojang.com/v1/objects/'.FakeVersions::SHA1.'/server.jar', 'server.jar', 'sha1', FakeVersions::SHA1);
        $downloads = $loader === 'fabric'
            ? [$vanilla, new Download('https://maven.fabricmc.net/net/fabricmc/fabric-installer/1.1.2/fabric-installer-1.1.2.jar', 'fabric-installer.jar', 'sha256', FakeVersions::SHA256, intoServerFolder: false)]
            : [new Download("https://maven.neoforged.net/releases/net/neoforged/neoforge/{$loaderVersion}/neoforge-{$loaderVersion}-installer.jar", 'neoforge-installer.jar', 'sha256', FakeVersions::SHA256, intoServerFolder: false)];
        $install = new LoaderInstall($loader, $minecraft, $loaderVersion, $downloads);

        return new PackPlan(
            Software::Modrinth,
            "{$title} ({$install->label()}, Minecraft {$minecraft})",
            mrpack: new Download($version->fileUrl, 'pack.mrpack', 'sha512', self::SHA512, intoServerFolder: false),
            files: [
                new PackFile('mods/lithium-0.15.0.jar', 'https://cdn.modrinth.com/data/gvQqBUqZ/versions/x/lithium-0.15.0.jar', self::SHA512),
                new PackFile('config/Cobble Pack/settings.json', 'https://github.com/example/cobble/raw/main/settings.json', self::SHA512),
            ],
            loader: $install,
        );
    }

    private function guard(): void
    {
        $fail = $this->cache->get(self::FAIL_KEY);

        if (is_array($fail)) {
            throw new ModrinthException((string) $fail[0], permanent: (bool) $fail[1]);
        }
    }
}
