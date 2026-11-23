<?php

namespace App\Minecraft\Modrinth;

use App\Minecraft\Download;
use App\Minecraft\Packs\PackPlan;
use App\WorldStorage\UnsafeArchive;
use App\WorldStorage\ZipInspector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use ZipArchive;

/**
 * Modrinth's public API (v2, no key) for modpacks (#5352). Search and version lists are
 * cached for ten minutes, a version for a day, as plain arrays. plan() downloads the
 * .mrpack into a temp file in the app (packs are small: the mods themselves come from their
 * links), checks its SHA-512 and every entry like a world upload, and reads the index.
 */
class OnlineModrinth implements ModrinthSource
{
    public const API = 'https://api.modrinth.com/v2';

    public function __construct(private readonly ModLoaders $loaders) {}

    public function search(string $query): array
    {
        $query = mb_substr(trim($query), 0, 100);

        return Cache::remember('mvm:modrinth:search:'.sha1($query), 600, function () use ($query): array {
            $hits = $this->get('/search', [
                'query' => $query,
                'facets' => json_encode([['project_type:modpack'], ['server_side:required', 'server_side:optional']]),
                'limit' => 20,
            ])['hits'] ?? null;

            if (! is_array($hits)) {
                throw new ModrinthException('Modrinth\'s search came back without results.');
            }

            $list = [];

            foreach ($hits as $hit) {
                if (is_array($hit) && is_string($hit['project_id'] ?? null) && preg_match(ModrinthVersion::ID_PATTERN, $hit['project_id']) === 1) {
                    $list[] = [
                        'id' => $hit['project_id'],
                        'slug' => is_string($hit['slug'] ?? null) ? $hit['slug'] : $hit['project_id'],
                        'title' => is_string($hit['title'] ?? null) ? $hit['title'] : $hit['project_id'],
                        'description' => is_string($hit['description'] ?? null) ? $hit['description'] : '',
                        'icon_url' => is_string($hit['icon_url'] ?? null) && str_starts_with($hit['icon_url'], 'https://') ? $hit['icon_url'] : null,
                        'downloads' => is_int($hit['downloads'] ?? null) ? $hit['downloads'] : 0,
                    ];
                }
            }

            return $list;
        });
    }

    public function title(string $projectId): string
    {
        $this->checkId($projectId);

        $project = Cache::remember('mvm:modrinth:project:'.$projectId, 86400, fn (): array => $this->get("/project/{$projectId}"));

        return is_string($project['title'] ?? null) ? $project['title'] : $projectId;
    }

    public function versions(string $projectId): array
    {
        $this->checkId($projectId);

        $list = Cache::remember('mvm:modrinth:versions:'.$projectId, 600, function () use ($projectId): array {
            $versions = $this->get("/project/{$projectId}/version", ['include_changelog' => 'false']);

            return array_values(array_filter($versions, fn ($v): bool => is_array($v) && ModrinthVersion::fromApi($v) !== null));
        });

        return array_slice(array_values(array_filter(array_map(fn (array $v): ?ModrinthVersion => ModrinthVersion::fromApi($v), $list))), 0, 50);
    }

    public function version(string $versionId): ModrinthVersion
    {
        $this->checkId($versionId);

        $json = Cache::remember('mvm:modrinth:version:'.$versionId, 86400, fn (): array => $this->get("/version/{$versionId}"));
        $version = ModrinthVersion::fromApi($json);

        if ($version === null) {
            throw new ModrinthException('That Modrinth version has no .mrpack this app can install (it needs Fabric, Quilt, NeoForge or Forge).', permanent: true);
        }

        return $version;
    }

    public function plan(string $versionId, string $title): PackPlan
    {
        $version = $this->version($versionId);

        try {
            $mrpack = new Download($version->fileUrl, 'pack.mrpack', 'sha512', $version->fileSha512, intoServerFolder: false);
        } catch (InvalidArgumentException $e) {
            throw new ModrinthException("The pack's .mrpack link looks wrong: {$e->getMessage()}", permanent: true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mvm-mrpack-');

        if ($tmp === false) {
            throw new ModrinthException('No temp file for the .mrpack.');
        }

        try {
            try {
                $this->client()->timeout(300)->sink($tmp)->get($version->fileUrl)->throw();
            } catch (ConnectionException|RequestException $e) {
                throw new ModrinthException("Couldn't download the .mrpack: {$e->getMessage()}");
            }

            if (hash_file('sha512', $tmp) !== $version->fileSha512) {
                throw new ModrinthException('The downloaded .mrpack doesn\'t match the SHA-512 Modrinth lists.');
            }

            return ModrinthIndex::plan(self::readIndex($tmp), $mrpack, $title, $this->loaders);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array<mixed>
     *
     * @throws ModrinthException
     */
    public static function readIndex(string $path): array
    {
        try {
            ZipInspector::assertSafe($path);
        } catch (UnsafeArchive $e) {
            throw new ModrinthException("The .mrpack isn't safe to unpack: {$e->getMessage()}", permanent: true);
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ModrinthException('The .mrpack isn\'t a zip file.', permanent: true);
        }

        try {
            $json = $zip->getFromName('modrinth.index.json');
        } finally {
            $zip->close();
        }

        $index = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($index)) {
            throw new ModrinthException('The .mrpack has no readable modrinth.index.json.', permanent: true);
        }

        return $index;
    }

    private function checkId(string $id): void
    {
        if (preg_match(ModrinthVersion::ID_PATTERN, $id) !== 1) {
            throw new ModrinthException('That isn\'t a Modrinth id.', permanent: true);
        }
    }

    private function client(): PendingRequest
    {
        // Modrinth asks API users to name their app in the User-Agent.
        return Http::timeout(10)->connectTimeout(5)
            ->withUserAgent('minecraft-vps-manager (+https://github.com/ShayPunter/minecraft-vps-manager)');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    private function get(string $path, array $query = []): array
    {
        try {
            $response = $this->client()->acceptJson()->get(self::API.$path, $query);

            if ($response->notFound()) {
                throw new ModrinthException('Modrinth has no such modpack or version.', permanent: true);
            }

            $json = $response->throw()->json();
        } catch (ConnectionException|RequestException $e) {
            throw new ModrinthException("Couldn't reach Modrinth: {$e->getMessage()}");
        }

        if (! is_array($json)) {
            throw new ModrinthException('Modrinth answered something that isn\'t JSON.');
        }

        return $json;
    }
}
