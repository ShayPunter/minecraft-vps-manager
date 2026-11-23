<?php

namespace App\Minecraft;

/**
 * What a server runs (#5351). Custom is the admin's own start command and files (a server
 * pack, an uploaded world); the others are installed by the app on the first start.
 * Modpacks (#5352) come from a server-pack zip URL or from Modrinth.
 */
enum Software: string
{
    case Custom = 'custom';
    case Vanilla = 'vanilla';
    case Paper = 'paper';
    case Fabric = 'fabric';
    case PackUrl = 'pack_url';
    case Modrinth = 'modrinth';

    public function label(): string
    {
        return match ($this) {
            self::Custom => 'My own start command',
            self::Vanilla => 'Vanilla',
            self::Paper => 'Paper',
            self::Fabric => 'Fabric',
            self::PackUrl => 'Modpack from a server-pack URL',
            self::Modrinth => 'Modrinth modpack',
        };
    }

    public function installs(): bool
    {
        return $this !== self::Custom;
    }

    /** A modpack (#5352): started by the mvm-launch.sh its install writes, memory in user_jvm_args.txt. */
    public function isPack(): bool
    {
        return $this === self::PackUrl || $this === self::Modrinth;
    }

    /**
     * Everything the app installs on a first start without a saved world.
     *
     * @return list<self>
     */
    public static function installable(): array
    {
        return [self::Vanilla, self::Paper, self::Fabric, self::PackUrl, self::Modrinth];
    }

    /**
     * The ones picked by a Minecraft version from a VersionSource list.
     *
     * @return list<self>
     */
    public static function versioned(): array
    {
        return [self::Vanilla, self::Paper, self::Fabric];
    }
}
