<?php

namespace App\Minecraft;

use InvalidArgumentException;

/**
 * One file prepare.sh downloads and checks against the hash its source publishes (#5351).
 * The URL and file name end up in a shell script, so both are checked here: https, one of
 * the hosts below, and no character a shell or curl would read as anything but a path.
 */
final readonly class Download
{
    public const HOSTS = ['piston-data.mojang.com', 'fill-data.papermc.io', 'maven.fabricmc.net'];

    private const URL_PATTERN = '#^https://([a-z0-9.-]+)/[A-Za-z0-9._/+-]+$#';

    private const FILE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.jar$/';

    /**
     * @param  'sha1'|'sha256'  $algorithm
     * @param  string  $file  where it goes: in the server folder, or the work folder for an installer
     */
    public function __construct(
        public string $url,
        public string $file,
        public string $algorithm,
        public string $hash,
        public bool $intoServerFolder = true,
    ) {
        if (preg_match(self::URL_PATTERN, $url, $m) !== 1 || ! in_array($m[1], self::HOSTS, true) || str_contains($url, '..')) {
            throw new InvalidArgumentException("Refusing to download from [{$url}].");
        }

        if (preg_match(self::FILE_PATTERN, $file) !== 1) {
            throw new InvalidArgumentException("Refusing file name [{$file}].");
        }

        $length = $algorithm === 'sha1' ? 40 : 64;

        if (preg_match('/^[0-9a-f]{'.$length.'}$/', $hash) !== 1) {
            throw new InvalidArgumentException("The {$algorithm} for {$file} isn't a valid hash.");
        }
    }
}
