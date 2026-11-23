<?php

namespace App\Waker;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server icons for the waker's server-list entry (#5620). The game wants a 64×64 PNG as a data
 * URI. The sweep fetches each wake-on-join server's `icon_url` (an admin's https link) and
 * caches the resized PNG; the waker only ever reads the cache, so a slow image host never
 * holds up a ping. Needs GD (Forge's PHP has it); without it there's simply no icon.
 */
class Favicons
{
    private const TTL_SECONDS = 86_400;

    /** A failed fetch is tried again after this long. */
    private const RETRY_SECONDS = 3_600;

    private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    public function get(Server $server): ?string
    {
        $cached = Cache::get(self::key($server));

        return is_array($cached) && ($cached['url'] ?? null) === $server->icon_url && is_string($cached['data'] ?? null)
            ? $cached['data']
            : null;
    }

    /**
     * Fetch and resize the icon unless the cache already has this URL (or a recent failure).
     */
    public function warm(Server $server): void
    {
        $url = $server->icon_url;

        if ($url === null) {
            Cache::forget(self::key($server));

            return;
        }

        $cached = Cache::get(self::key($server));

        if (is_array($cached) && ($cached['url'] ?? null) === $url) {
            return;
        }

        $data = $this->fetch($url);
        Cache::put(self::key($server), ['url' => $url, 'data' => $data], $data !== null ? self::TTL_SECONDS : self::RETRY_SECONDS);
    }

    public static function supported(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagepng');
    }

    private function fetch(string $url): ?string
    {
        if (! self::supported() || ! str_starts_with($url, 'https://')) {
            return null;
        }

        try {
            $response = Http::timeout(10)->connectTimeout(5)->get($url);
        } catch (Throwable $e) {
            Log::info("Waker icon not fetched from {$url}: {$e->getMessage()}");

            return null;
        }

        $body = $response->body();

        if (! $response->successful() || $body === '' || strlen($body) > self::MAX_IMAGE_BYTES) {
            Log::info("Waker icon not fetched from {$url}: HTTP {$response->status()}, ".strlen($body).' bytes.');

            return null;
        }

        return self::toFavicon($body);
    }

    /**
     * Any image GD reads → a 64×64 PNG data URI, the picture fitted inside and centred on a
     * transparent square.
     */
    public static function toFavicon(string $image): ?string
    {
        if (! self::supported()) {
            return null;
        }

        $source = @imagecreatefromstring($image);

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = 64 / max($width, $height, 1);
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $icon = imagecreatetruecolor(64, 64);
        imagealphablending($icon, false);
        imagesavealpha($icon, true);
        imagefill($icon, 0, 0, (int) imagecolorallocatealpha($icon, 0, 0, 0, 127));
        imagealphablending($icon, true);
        imagecopyresampled($icon, $source, intdiv(64 - $w, 2), intdiv(64 - $h, 2), 0, 0, $w, $h, $width, $height);

        ob_start();
        imagepng($icon);
        $png = (string) ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private static function key(Server $server): string
    {
        return "mvm:waker:favicon:{$server->id}";
    }
}
