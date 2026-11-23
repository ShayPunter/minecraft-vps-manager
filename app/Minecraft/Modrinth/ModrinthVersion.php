<?php

namespace App\Minecraft\Modrinth;

use App\Minecraft\Packs\LoaderInstall;

/**
 * One version of a Modrinth modpack (#5352), as the form and the install need it: the
 * project it belongs to, the Minecraft version and loader it lists, and its .mrpack file.
 */
final readonly class ModrinthVersion
{
    public const ID_PATTERN = '/^[A-Za-z0-9]{8}$/';

    public function __construct(
        public string $id,
        public string $projectId,
        public string $name,
        public string $versionNumber,
        public string $minecraftVersion,
        public string $loader,
        public string $fileUrl,
        public string $fileSha512,
        public int $fileSize,
    ) {}

    /**
     * From Modrinth's version JSON, or null when it isn't a pack this app can install (no
     * .mrpack file, or no loader it knows).
     *
     * @param  array<mixed>  $json
     */
    public static function fromApi(array $json): ?self
    {
        $id = $json['id'] ?? null;
        $project = $json['project_id'] ?? null;
        $games = $json['game_versions'] ?? null;
        $loaders = array_values(array_intersect(is_array($json['loaders'] ?? null) ? $json['loaders'] : [], LoaderInstall::LOADERS));

        if (! is_string($id) || ! is_string($project) || ! is_array($games) || ! is_string($games[0] ?? null) || $loaders === []) {
            return null;
        }

        $file = null;

        foreach (is_array($json['files'] ?? null) ? $json['files'] : [] as $candidate) {
            if (is_array($candidate) && is_string($candidate['url'] ?? null) && str_ends_with($candidate['url'], '.mrpack')
                && ($file === null || ($candidate['primary'] ?? false) === true)) {
                $file = $candidate;
            }
        }

        if ($file === null || ! is_string($file['hashes']['sha512'] ?? null)) {
            return null;
        }

        return new self(
            $id,
            $project,
            is_string($json['name'] ?? null) ? $json['name'] : $id,
            is_string($json['version_number'] ?? null) ? $json['version_number'] : $id,
            $games[0],
            $loaders[0],
            $file['url'],
            strtolower($file['hashes']['sha512']),
            is_int($file['size'] ?? null) ? $file['size'] : 0,
        );
    }

    /**
     * @return array{id: string, name: string, version_number: string, minecraft_version: string, loader: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version_number' => $this->versionNumber,
            'minecraft_version' => $this->minecraftVersion,
            'loader' => $this->loader,
        ];
    }
}
