<?php

namespace App\Minecraft\Modrinth;

use App\Minecraft\Download;
use App\Minecraft\Packs\PackFile;
use App\Minecraft\Packs\PackPlan;
use App\Minecraft\Software;
use App\Minecraft\VersionSourceException;
use InvalidArgumentException;

/**
 * A .mrpack's modrinth.index.json (#5352, Modrinth's format version 1) turned into a
 * PackPlan: the files a server needs (client-only ones skipped), each with its SHA-512, and
 * the loader from the index's dependencies. Anything the format doesn't allow (a path
 * outside the folder, a host other than Modrinth's list, a missing hash) refuses the pack.
 */
class ModrinthIndex
{
    private const DEPENDENCY_LOADERS = [
        'fabric-loader' => 'fabric',
        'quilt-loader' => 'quilt',
        'neoforge' => 'neoforge',
        'forge' => 'forge',
    ];

    /**
     * @param  array<mixed>  $index
     *
     * @throws ModrinthException
     */
    public static function plan(array $index, Download $mrpack, string $title, ModLoaders $loaders): PackPlan
    {
        if (($index['formatVersion'] ?? null) !== 1 || ($index['game'] ?? null) !== 'minecraft') {
            throw new ModrinthException('The pack\'s index isn\'t a Minecraft modpack in a format this app knows.', permanent: true);
        }

        $dependencies = is_array($index['dependencies'] ?? null) ? $index['dependencies'] : [];
        $minecraft = $dependencies['minecraft'] ?? null;

        if (! is_string($minecraft)) {
            throw new ModrinthException('The pack\'s index doesn\'t say which Minecraft version it needs.', permanent: true);
        }

        $loader = null;

        foreach (self::DEPENDENCY_LOADERS as $key => $name) {
            if (is_string($dependencies[$key] ?? null)) {
                $loader = [$name, $dependencies[$key]];
                break;
            }
        }

        if ($loader === null) {
            throw new ModrinthException('The pack needs a mod loader this app can\'t install (it knows Fabric, Quilt, NeoForge and Forge).', permanent: true);
        }

        $files = [];

        try {
            foreach (is_array($index['files'] ?? null) ? $index['files'] : [] as $entry) {
                if (! is_array($entry) || ($entry['env']['server'] ?? 'required') === 'unsupported') {
                    continue; // Client-only (shaders, minimaps and the like).
                }

                $path = $entry['path'] ?? null;
                $sha512 = $entry['hashes']['sha512'] ?? null;
                $urls = is_array($entry['downloads'] ?? null) ? $entry['downloads'] : [];

                if (! is_string($path) || ! is_string($sha512)) {
                    throw new InvalidArgumentException('A file in the index has no path or SHA-512.');
                }

                $files[] = self::file($path, $urls, strtolower($sha512));
            }

            $install = $loaders->install($loader[0], $minecraft, $loader[1]);
            $description = trim(mb_substr((string) preg_replace('/[^A-Za-z0-9 .,()+_:\/-]/', '', $title), 0, 80));

            return new PackPlan(
                Software::Modrinth,
                ($description !== '' ? $description : 'Modrinth pack')." ({$install->label()}, Minecraft {$minecraft})",
                mrpack: $mrpack,
                files: $files,
                loader: $install,
            );
        } catch (InvalidArgumentException $e) {
            throw new ModrinthException("The pack can't be installed: {$e->getMessage()}", permanent: true);
        } catch (VersionSourceException $e) {
            throw new ModrinthException($e->getMessage());
        }
    }

    /**
     * The first download URL on a host the format allows.
     *
     * @param  array<mixed>  $urls
     */
    private static function file(string $path, array $urls, string $sha512): PackFile
    {
        $last = null;

        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }

            try {
                return new PackFile($path, $url, $sha512);
            } catch (InvalidArgumentException $e) {
                $last = $e;
            }
        }

        throw $last ?? new InvalidArgumentException("{$path} has no download link.");
    }
}
