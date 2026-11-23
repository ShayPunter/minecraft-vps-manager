<?php

namespace App\Minecraft\Modrinth;

use App\Minecraft\Download;
use App\Minecraft\Packs\LoaderInstall;
use App\Minecraft\Software;
use App\Minecraft\VersionSource;
use App\Minecraft\VersionSourceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * The downloads for the loader a Modrinth pack names (#5352), each with its source's hash:
 *
 *   Fabric:   the vanilla jar and Fabric's installer, as a Fabric server gets them (#5351)
 *   Quilt:    the vanilla jar and Quilt's newest installer (meta.quiltmc.org lists its SHA-256)
 *   NeoForge: maven.neoforged.net's installer jar and the .sha256 next to it
 *   Forge:    maven.minecraftforge.net's installer jar and the .sha256 next to it
 */
class ModLoaders
{
    public const QUILT_META = 'https://meta.quiltmc.org/v3/versions/installer';

    public const NEOFORGE_MAVEN = 'https://maven.neoforged.net/releases/net/neoforged/neoforge';

    public const FORGE_MAVEN = 'https://maven.minecraftforge.net/net/minecraftforge/forge';

    public function __construct(private readonly VersionSource $versions) {}

    /**
     * @throws VersionSourceException
     * @throws InvalidArgumentException when a version or URL looks wrong
     */
    public function install(string $loader, string $minecraft, string $loaderVersion): LoaderInstall
    {
        if (! in_array($minecraft, $this->versions->versions(Software::Vanilla), true)) {
            throw new InvalidArgumentException("Minecraft {$minecraft} isn't one this app installs (1.17 on).");
        }

        $downloads = match ($loader) {
            'fabric' => $this->versions->plan(Software::Fabric, $minecraft)->downloads,
            'quilt' => [$this->vanillaJar($minecraft), $this->quiltInstaller()],
            'neoforge' => [$this->mavenInstaller(self::NEOFORGE_MAVEN."/{$loaderVersion}/neoforge-{$loaderVersion}-installer.jar", 'neoforge-installer.jar')],
            'forge' => [$this->mavenInstaller(self::FORGE_MAVEN."/{$minecraft}-{$loaderVersion}/forge-{$minecraft}-{$loaderVersion}-installer.jar", 'forge-installer.jar')],
            default => throw new InvalidArgumentException("Unknown mod loader [{$loader}]."),
        };

        return new LoaderInstall($loader, $minecraft, $loaderVersion, $downloads);
    }

    private function vanillaJar(string $minecraft): Download
    {
        return $this->versions->plan(Software::Vanilla, $minecraft)->downloads[0];
    }

    private function quiltInstaller(): Download
    {
        $installers = $this->get(self::QUILT_META, 'Quilt')->json();
        $first = is_array($installers) ? ($installers[0] ?? null) : null;

        if (! is_array($first) || ! is_string($first['url'] ?? null) || ! is_string($first['hashes']['sha256'] ?? null)) {
            throw new VersionSourceException('Quilt lists no installer.');
        }

        return new Download($first['url'], 'quilt-installer.jar', 'sha256', strtolower($first['hashes']['sha256']), intoServerFolder: false);
    }

    private function mavenInstaller(string $url, string $file): Download
    {
        $sha256 = strtolower(trim(strtok($this->get($url.'.sha256', 'the loader\'s Maven')->body(), " \n") ?: ''));

        return new Download($url, $file, 'sha256', $sha256, intoServerFolder: false);
    }

    private function get(string $url, string $who): Response
    {
        try {
            return Http::timeout(15)->connectTimeout(5)
                ->withUserAgent('minecraft-vps-manager (+https://github.com/ShayPunter/minecraft-vps-manager)')
                ->get($url)->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new VersionSourceException("Couldn't reach {$who}: {$e->getMessage()}");
        }
    }
}
