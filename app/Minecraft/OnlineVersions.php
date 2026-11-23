<?php

namespace App\Minecraft;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Minecraft versions and server jars from their own sources (#5351):
 *
 *   Vanilla: Mojang's version manifest; each version's JSON has the server jar, its SHA-1
 *            and the Java version (javaVersion.majorVersion).
 *   Paper:   PaperMC's Fill API (v3): versions with their minimum Java, builds newest first,
 *            each with a SHA-256. Only STABLE builds are installed.
 *   Fabric:  Fabric's meta API for game, loader and installer versions; the installer jar
 *            comes from Fabric's Maven, whose .sha256 file sits next to it. The vanilla jar
 *            the loader runs on comes from Mojang as above.
 *
 * Lists are cached for an hour and a version's Java for a day, as plain arrays.
 */
class OnlineVersions implements VersionSource
{
    public const MOJANG_MANIFEST = 'https://piston-meta.mojang.com/mc/game/version_manifest_v2.json';

    public const PAPER_API = 'https://fill.papermc.io/v3/projects/paper';

    public const FABRIC_META = 'https://meta.fabricmc.net/v2';

    // Java 16+ from here on, so every listed version runs on a Java this app installs.
    private const OLDEST = '1.17';

    public function versions(Software $software): array
    {
        return match ($software) {
            Software::Vanilla => array_keys($this->mojang()),
            Software::Paper => array_keys($this->paper()),
            Software::Fabric => $this->fabricGames(),
            // Modpacks (#5352) name their own version.
            Software::Custom, Software::PackUrl, Software::Modrinth => [],
        };
    }

    public function javaFor(Software $software, string $version): int
    {
        $this->requireListed($software, $version);

        if ($software === Software::Paper) {
            return $this->paper()[$version];
        }

        $java = $this->mojangVersion($version)['javaVersion']['majorVersion'] ?? null;

        if (! is_int($java)) {
            throw new VersionSourceException("Mojang's details for {$version} don't say which Java it needs.");
        }

        return $java;
    }

    public function plan(Software $software, string $version): InstallPlan
    {
        $this->requireListed($software, $version);

        try {
            return match ($software) {
                Software::Vanilla => new InstallPlan($software, $version, "Vanilla {$version}", [$this->vanillaJar($version)]),
                Software::Paper => $this->paperPlan($version),
                Software::Fabric => $this->fabricPlan($version),
                Software::Custom, Software::PackUrl, Software::Modrinth => throw new InvalidArgumentException('Only Vanilla, Paper and Fabric install from a version list.'),
            };
        } catch (InvalidArgumentException $e) {
            throw new VersionSourceException("The {$software->label()} download for {$version} looks wrong: {$e->getMessage()}");
        }
    }

    private function requireListed(Software $software, string $version): void
    {
        if (! in_array($version, $this->versions($software), true)) {
            throw new VersionSourceException("{$software->label()} has no release {$version}.");
        }
    }

    /**
     * @return array<string, string> release id => its JSON's URL, newest first, down to 1.17
     */
    private function mojang(): array
    {
        return Cache::remember('mvm:versions:mojang:v1', 3600, function (): array {
            $versions = $this->get(self::MOJANG_MANIFEST, 'Mojang')['versions'] ?? null;

            if (! is_array($versions)) {
                throw new VersionSourceException("Mojang's version list came back without versions.");
            }

            $releases = [];

            foreach ($versions as $version) {
                if (($version['type'] ?? null) !== 'release' || ! is_string($version['id'] ?? null) || ! is_string($version['url'] ?? null)) {
                    continue;
                }

                $releases[$version['id']] = $version['url'];

                if ($version['id'] === self::OLDEST) {
                    break;
                }
            }

            return $releases;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function mojangVersion(string $version): array
    {
        $url = $this->mojang()[$version];

        return Cache::remember('mvm:versions:mojang:'.sha1($url), 86400, fn (): array => $this->get($url, 'Mojang'));
    }

    private function vanillaJar(string $version): Download
    {
        $server = $this->mojangVersion($version)['downloads']['server'] ?? null;

        if (! is_array($server) || ! is_string($server['url'] ?? null) || ! is_string($server['sha1'] ?? null)) {
            throw new VersionSourceException("Mojang has no server jar for {$version}.");
        }

        return new Download($server['url'], 'server.jar', 'sha1', $server['sha1']);
    }

    /**
     * @return array<string, int> version => minimum Java, newest first
     */
    private function paper(): array
    {
        return Cache::remember('mvm:versions:paper:v1', 3600, function (): array {
            $versions = $this->get(self::PAPER_API.'/versions', 'PaperMC')['versions'] ?? null;

            if (! is_array($versions)) {
                throw new VersionSourceException("PaperMC's version list came back without versions.");
            }

            $list = [];

            foreach ($versions as $entry) {
                $id = $entry['version']['id'] ?? null;
                $java = $entry['version']['java']['version']['minimum'] ?? null;

                // Release versions only (no -rc / -pre), and only those on Java 16+ (1.17 on).
                if (is_string($id) && is_int($java) && $java >= 16 && preg_match('/^\d+(\.\d+)+$/', $id) === 1) {
                    $list[$id] = $java;
                }
            }

            return $list;
        });
    }

    private function paperPlan(string $version): InstallPlan
    {
        $builds = $this->get(self::PAPER_API.'/versions/'.rawurlencode($version).'/builds', 'PaperMC');

        foreach ($builds as $build) {
            if (is_array($build) && ($build['channel'] ?? null) === 'STABLE') {
                $jar = $build['downloads']['server:default'] ?? null;

                if (! is_array($jar) || ! is_string($jar['url'] ?? null) || ! is_string($jar['checksums']['sha256'] ?? null)) {
                    break;
                }

                $id = is_int($build['id'] ?? null) ? $build['id'] : 0;

                return new InstallPlan(Software::Paper, $version, "Paper {$version} (build {$id})", [
                    new Download($jar['url'], 'server.jar', 'sha256', $jar['checksums']['sha256']),
                ]);
            }
        }

        throw new VersionSourceException("Paper has no stable build for {$version} yet. Pick another version, or try again later.");
    }

    /**
     * @return list<string>
     */
    private function fabricGames(): array
    {
        $stable = Cache::remember('mvm:versions:fabric-games:v1', 3600, function (): array {
            $games = $this->get(self::FABRIC_META.'/versions/game', 'Fabric');

            return array_values(array_map(
                fn (array $game): string => (string) $game['version'],
                array_filter($games, fn ($game): bool => is_array($game) && ($game['stable'] ?? false) === true && is_string($game['version'] ?? null)),
            ));
        });

        // Fabric's stable game versions are Mojang's releases; Mojang's list sets the 1.17 cut.
        return array_values(array_intersect($stable, array_keys($this->mojang())));
    }

    private function fabricPlan(string $version): InstallPlan
    {
        $loader = $this->firstStable($this->get(self::FABRIC_META.'/versions/loader', 'Fabric'), 'version');
        $installers = $this->get(self::FABRIC_META.'/versions/installer', 'Fabric');
        $installerUrl = $this->firstStable($installers, 'url');
        $installerVersion = $this->firstStable($installers, 'version');

        $sha256 = strtolower(trim(strtok($this->getText($installerUrl.'.sha256', 'Fabric'), " \n") ?: ''));

        return new InstallPlan(
            Software::Fabric,
            $version,
            "Fabric {$version} (loader {$loader}, installer {$installerVersion})",
            [
                $this->vanillaJar($version),
                new Download($installerUrl, 'fabric-installer.jar', 'sha256', $sha256, intoServerFolder: false),
            ],
            fabricLoader: $loader,
        );
    }

    /**
     * @param  array<mixed>  $entries
     */
    private function firstStable(array $entries, string $field): string
    {
        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['stable'] ?? false) === true && is_string($entry[$field] ?? null)) {
                return $entry[$field];
            }
        }

        throw new VersionSourceException('Fabric lists no stable loader or installer.');
    }

    private function client(): PendingRequest
    {
        return Http::timeout(10)->connectTimeout(5)
            ->withUserAgent('minecraft-vps-manager (+https://github.com/ShayPunter/minecraft-vps-manager)')
            ->acceptJson();
    }

    /**
     * @return array<mixed>
     */
    private function get(string $url, string $who): array
    {
        try {
            $json = $this->client()->get($url)->throw()->json();
        } catch (ConnectionException|RequestException $e) {
            throw new VersionSourceException("Couldn't reach {$who}'s version list: {$e->getMessage()}");
        }

        if (! is_array($json)) {
            throw new VersionSourceException("{$who} answered something that isn't a version list.");
        }

        return $json;
    }

    private function getText(string $url, string $who): string
    {
        try {
            return $this->client()->accept('text/plain')->get($url)->throw()->body();
        } catch (ConnectionException|RequestException $e) {
            throw new VersionSourceException("Couldn't read {$who}'s checksum: {$e->getMessage()}");
        }
    }
}
