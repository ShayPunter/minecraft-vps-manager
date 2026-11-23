<?php

namespace App\Minecraft\Packs;

use App\WorldStorage\ZipInspector;
use InvalidArgumentException;

/**
 * One file from a Modrinth pack's index (#5352): where it goes in the server folder, where
 * prepare.sh downloads it from and the SHA-512 it must have. The three go to the VPS as one
 * line of /root/.mvm/mrfiles (`<sha512> <url> <path>`), so none may hold a newline, the URL
 * no space, and the path nothing a shell would read as anything but a path.
 */
final readonly class PackFile
{
    /** The hosts Modrinth's .mrpack format allows downloads from. */
    public const HOSTS = ['cdn.modrinth.com', 'github.com', 'raw.githubusercontent.com', 'gitlab.com'];

    // The path is only ever data (read with `read -r`, used as "$DIR/$path"), never part of
    // the script, so any printable character works ("qrafty's-capitalized-font-3.5.zip" in
    // a real pack); no control characters or backslashes, and ZipInspector's rules on top.
    public const PATH_PATTERN = '#^[^\x00-\x1F\x7F\\\\/]+(/[^\x00-\x1F\x7F\\\\/]+)*$#u';

    private const URL_PATTERN = '#^https://([a-z0-9.-]+)/[A-Za-z0-9._~/+%!&=,;:@()-]+$#';

    public function __construct(
        public string $path,
        public string $url,
        public string $sha512,
    ) {
        if (preg_match(self::PATH_PATTERN, $path) !== 1 || ! ZipInspector::isSafeName($path) || str_contains($path, '/./') || str_starts_with($path, './')) {
            throw new InvalidArgumentException("Refusing pack file path [{$path}].");
        }

        if (preg_match(self::URL_PATTERN, $url, $m) !== 1 || ! in_array($m[1], self::HOSTS, true) || str_contains($url, '..')) {
            throw new InvalidArgumentException("Refusing to download {$path} from [{$url}].");
        }

        if (preg_match('/^[0-9a-f]{128}$/', $sha512) !== 1) {
            throw new InvalidArgumentException("The SHA-512 for {$path} isn't a valid hash.");
        }
    }

    public function line(): string
    {
        return "{$this->sha512} {$this->url} {$this->path}\n";
    }
}
