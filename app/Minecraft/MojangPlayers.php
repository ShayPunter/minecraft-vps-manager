<?php

namespace App\Minecraft;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Mojang's public bulk name lookup (no key needed): up to 10 names a call, unknown names
 * left out of the answer. Found accounts are cached for a day.
 */
class MojangPlayers implements PlayerLookup
{
    public const URL = 'https://api.minecraftservices.com/minecraft/profile/lookup/bulk/byname';

    public function find(array $names): array
    {
        $found = [];
        $missing = [];

        foreach (array_unique(array_map('strtolower', $names)) as $name) {
            $cached = Cache::get("mvm:player:{$name}");

            if (is_array($cached) && is_string($cached['uuid'] ?? null) && is_string($cached['name'] ?? null)) {
                $found[$name] = ['uuid' => $cached['uuid'], 'name' => $cached['name']];
            } else {
                $missing[] = $name;
            }
        }

        foreach (array_chunk($missing, 10) as $chunk) {
            try {
                $answer = Http::timeout(10)->connectTimeout(5)
                    ->withUserAgent('minecraft-vps-manager (+https://github.com/ShayPunter/minecraft-vps-manager)')
                    ->acceptJson()
                    ->post(self::URL, $chunk)
                    ->throw()
                    ->json();
            } catch (ConnectionException|RequestException $e) {
                throw new VersionSourceException("Couldn't reach Mojang to look up player names: {$e->getMessage()}");
            }

            foreach (is_array($answer) ? $answer : [] as $profile) {
                if (! is_array($profile) || ! is_string($profile['id'] ?? null) || ! is_string($profile['name'] ?? null)
                    || preg_match('/^[0-9a-f]{32}$/', $profile['id']) !== 1) {
                    continue;
                }

                $account = ['uuid' => self::dashed($profile['id']), 'name' => $profile['name']];
                $found[strtolower($profile['name'])] = $account;
                Cache::put('mvm:player:'.strtolower($profile['name']), $account, 86400);
            }
        }

        return $found;
    }

    public static function dashed(string $hex): string
    {
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
