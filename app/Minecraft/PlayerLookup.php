<?php

namespace App\Minecraft;

/**
 * Minecraft account names to UUIDs (#5377), for whitelist.json and ops.json: Mojang's
 * bulk lookup (MojangPlayers), or a fake in tests and E2E.
 */
interface PlayerLookup
{
    /**
     * @param  list<string>  $names
     * @return array<string, array{uuid: string, name: string}> lowercased name => the account (its own spelling, dashed UUID); unknown names are left out
     *
     * @throws VersionSourceException when Mojang can't be reached
     */
    public function find(array $names): array;
}
