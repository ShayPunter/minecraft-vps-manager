<?php

namespace App\Minecraft\Packs;

use App\Minecraft\Download;
use App\Minecraft\InstallPlan;
use InvalidArgumentException;

/**
 * The mod loader a Modrinth pack names (#5352) and how prepare.sh installs it:
 *
 *   fabric:   the vanilla jar + Fabric's installer (`server -mcversion -loader -dir`)
 *   quilt:    the vanilla jar + Quilt's installer (`install server <mc> <loader> --install-dir`)
 *   neoforge, forge: their installer with `--installServer` in the server folder, which
 *             fetches Minecraft and the libraries itself and writes unix_args.txt
 *
 * Installers go to the work folder; every download has its source's own hash.
 */
final readonly class LoaderInstall
{
    public const LOADERS = ['fabric', 'quilt', 'neoforge', 'forge'];

    /**
     * @param  list<Download>  $downloads
     */
    public function __construct(
        public string $loader,
        public string $minecraftVersion,
        public string $loaderVersion,
        public array $downloads,
    ) {
        if (! in_array($loader, self::LOADERS, true)) {
            throw new InvalidArgumentException("Unknown mod loader [{$loader}].");
        }

        foreach ([$minecraftVersion, $loaderVersion] as $value) {
            if (preg_match(InstallPlan::VERSION_PATTERN, $value) !== 1) {
                throw new InvalidArgumentException("Refusing version [{$value}].");
            }
        }

        if ($this->installer() === null) {
            throw new InvalidArgumentException("The {$loader} install needs its installer.");
        }
    }

    public function installer(): ?Download
    {
        foreach ($this->downloads as $download) {
            if (! $download->intoServerFolder) {
                return $download;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this->loader) {
            'fabric' => 'Fabric',
            'quilt' => 'Quilt',
            'neoforge' => 'NeoForge',
            default => 'Forge',
        }." {$this->loaderVersion}";
    }
}
