<?php

namespace App\Minecraft;

use InvalidArgumentException;

/**
 * What prepare.sh does to install a server with no saved world yet (#5351): download these
 * files, check each hash, and for Fabric run its installer in the server folder.
 */
final readonly class InstallPlan
{
    public const VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z._+-]{0,39}$/';

    /**
     * @param  list<Download>  $downloads
     * @param  string  $description  e.g. "Paper 26.3 (build 258)", printed in the run's log
     * @param  string|null  $fabricLoader  the Fabric loader version, when the installer runs
     */
    public function __construct(
        public Software $software,
        public string $version,
        public string $description,
        public array $downloads,
        public ?string $fabricLoader = null,
    ) {
        if (! $software->installs()) {
            throw new InvalidArgumentException('Custom servers have nothing to install.');
        }

        foreach ([$version, $fabricLoader] as $value) {
            if ($value !== null && preg_match(self::VERSION_PATTERN, $value) !== 1) {
                throw new InvalidArgumentException("Refusing version [{$value}].");
            }
        }

        if (preg_match('/^[A-Za-z0-9 .()+_-]{1,100}$/', $description) !== 1) {
            throw new InvalidArgumentException('Refusing the install description.');
        }

        if ($software === Software::Fabric && ($fabricLoader === null || $this->installer() === null)) {
            throw new InvalidArgumentException('A Fabric install needs a loader version and the installer.');
        }

        if ($downloads === []) {
            throw new InvalidArgumentException('Nothing to download.');
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
}
