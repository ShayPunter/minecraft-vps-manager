<?php

namespace App\Minecraft;

/**
 * What a server runs (#5351). Custom is the admin's own start command and files (a server
 * pack, an uploaded world); the others are installed by the app on the first start.
 */
enum Software: string
{
    case Custom = 'custom';
    case Vanilla = 'vanilla';
    case Paper = 'paper';
    case Fabric = 'fabric';

    public function label(): string
    {
        return match ($this) {
            self::Custom => 'My own start command',
            self::Vanilla => 'Vanilla',
            self::Paper => 'Paper',
            self::Fabric => 'Fabric',
        };
    }

    public function installs(): bool
    {
        return $this !== self::Custom;
    }

    /**
     * @return list<self>
     */
    public static function installable(): array
    {
        return [self::Vanilla, self::Paper, self::Fabric];
    }
}
