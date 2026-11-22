<?php

namespace App\Minecraft;

use Illuminate\Contracts\Cache\Repository;

/**
 * Minecraft versions as tests and E2E see them (#5351). Never calls anything. The state for
 * failWith() lives in the cache, so it survives artisan serve's separate processes.
 *
 * Every software lists 26.3, 26.2 and 1.21.11; 26.x needs Java 25, the rest Java 21.
 */
class FakeVersions implements VersionSource
{
    public const VERSIONS = ['26.3', '26.2', '1.21.11'];

    public const SHA1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const SHA256 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const FAIL_KEY = 'mvm:fake-versions:fail';

    public function __construct(private readonly Repository $cache) {}

    /** Every call fails with this message until reset(). */
    public function failWith(string $message): void
    {
        $this->cache->forever(self::FAIL_KEY, $message);
    }

    public function reset(): void
    {
        $this->cache->forget(self::FAIL_KEY);
    }

    public function versions(Software $software): array
    {
        $this->guard();

        return $software->installs() ? self::VERSIONS : [];
    }

    public function javaFor(Software $software, string $version): int
    {
        $this->listed($software, $version);

        return str_starts_with($version, '26.') ? 25 : 21;
    }

    public function plan(Software $software, string $version): InstallPlan
    {
        $this->listed($software, $version);

        $vanilla = new Download('https://piston-data.mojang.com/v1/objects/'.self::SHA1.'/server.jar', 'server.jar', 'sha1', self::SHA1);

        return match ($software) {
            Software::Vanilla => new InstallPlan($software, $version, "Vanilla {$version}", [$vanilla]),
            Software::Paper => new InstallPlan($software, $version, "Paper {$version} (build 1)", [
                new Download('https://fill-data.papermc.io/v1/objects/'.self::SHA256."/paper-{$version}-1.jar", 'server.jar', 'sha256', self::SHA256),
            ]),
            default => new InstallPlan(Software::Fabric, $version, "Fabric {$version} (loader 0.20.1, installer 1.1.2)", [
                $vanilla,
                new Download('https://maven.fabricmc.net/net/fabricmc/fabric-installer/1.1.2/fabric-installer-1.1.2.jar', 'fabric-installer.jar', 'sha256', self::SHA256, intoServerFolder: false),
            ], fabricLoader: '0.20.1'),
        };
    }

    private function listed(Software $software, string $version): void
    {
        if (! in_array($version, $this->versions($software), true)) {
            throw new VersionSourceException("{$software->label()} has no release {$version}.");
        }
    }

    private function guard(): void
    {
        $message = $this->cache->get(self::FAIL_KEY);

        if (is_string($message)) {
            throw new VersionSourceException($message);
        }
    }
}
