<?php

namespace App\Minecraft\Packs;

use App\Minecraft\Download;
use App\Minecraft\Software;
use InvalidArgumentException;

/**
 * What prepare.sh does to install a modpack on a server with no saved world yet (#5352).
 *
 *   PackUrl:  download the admin's server-pack zip (no hash is published for those, so its
 *             size and SHA-256 are printed in the run's log), check every entry like a world
 *             upload, unpack it (a zip holding one folder from inside it), and run a Forge /
 *             NeoForge installer it ships when there's no libraries/ folder yet.
 *   Modrinth: download the .mrpack (its SHA-512 from Modrinth), every server file in its
 *             index (each with the index's SHA-512), apply overrides/ then server-overrides/,
 *             and install the loader the index names.
 *
 * Either way it then writes mvm-launch.sh, which starts Java directly (never a pack's own
 * restart loop, so systemd's stop still saves and ends the server).
 */
final readonly class PackPlan
{
    // An https URL a shell may see single-quoted: no quotes, spaces, backslashes or `$`.
    public const URL_PATTERN = '#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~:/?\#@!&+,;=%()*-]*)?$#';

    /**
     * @param  list<PackFile>  $files  Modrinth only
     */
    public function __construct(
        public Software $software,
        public string $description,
        public ?string $packUrl = null,
        public ?Download $mrpack = null,
        public array $files = [],
        public ?LoaderInstall $loader = null,
    ) {
        if (preg_match('/^[A-Za-z0-9 .,()+_:\/-]{1,120}$/', $description) !== 1) {
            throw new InvalidArgumentException('Refusing the pack description.');
        }

        if ($software === Software::PackUrl) {
            if ($packUrl === null || ! self::validUrl($packUrl) || $mrpack !== null || $files !== [] || $loader !== null) {
                throw new InvalidArgumentException('A server-pack install needs just its https URL.');
            }
        } elseif ($software === Software::Modrinth) {
            if ($mrpack === null || $loader === null || $packUrl !== null) {
                throw new InvalidArgumentException('A Modrinth install needs the .mrpack and its loader.');
            }
        } else {
            throw new InvalidArgumentException('Only modpacks have a pack plan.');
        }
    }

    public static function validUrl(string $url): bool
    {
        return strlen($url) <= 2048 && preg_match(self::URL_PATTERN, $url) === 1;
    }

    public static function forUrl(string $url): self
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return new self(Software::PackUrl, mb_substr("Server pack from {$host}", 0, 120), packUrl: $url);
    }

    /** The VPS's copy of the index: one `<sha512> <url> <path>` line per file. */
    public function filesList(): string
    {
        return implode('', array_map(fn (PackFile $file): string => $file->line(), $this->files));
    }
}
