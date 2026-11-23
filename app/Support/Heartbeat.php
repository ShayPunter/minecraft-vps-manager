<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * "This ran just now" markers, so app:doctor can tell whether Forge's scheduler and queue
 * worker are really running. Stored as plain ISO strings (the cache refuses objects).
 */
class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE = 'queue';

    /** `servers:waker` (#5620), once a minute while it listens. */
    public const WAKER = 'waker';

    public static function beat(string $name): void
    {
        Cache::forever("mvm:heartbeat:{$name}", now()->toIso8601String());
    }

    public static function last(string $name): ?CarbonImmutable
    {
        $value = Cache::get("mvm:heartbeat:{$name}");

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
