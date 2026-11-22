<?php

namespace App\Minecraft;

/**
 * Player lookups as tests and E2E see them (#5377). Never calls anything: every valid name
 * exists, with a UUID made from the name, except names starting with "nobody", and the name
 * "mojang_down" makes the whole lookup fail.
 */
class FakePlayers implements PlayerLookup
{
    public function find(array $names): array
    {
        $found = [];

        foreach ($names as $name) {
            if (strtolower($name) === 'mojang_down') {
                throw new VersionSourceException("Couldn't reach Mojang to look up player names: timeout");
            }

            if (! str_starts_with(strtolower($name), 'nobody')) {
                $found[strtolower($name)] = ['uuid' => self::uuidFor($name), 'name' => $name];
            }
        }

        return $found;
    }

    public static function uuidFor(string $name): string
    {
        return MojangPlayers::dashed(md5('player:'.strtolower($name)));
    }
}
