<?php

namespace App\Servers;

use App\Models\Server;
use App\Models\ServerRun;

/**
 * Limits on starts by people who aren't signed in (#5526): guests on a public server's
 * home-page card or a link-only server's share link. Each start bills at least an hour
 * and takes a slot, so a script restarting a server after every idle stop costs money
 * and keeps friends from starting theirs. Signed-in players aren't limited here.
 *
 *  - a budget per server per day (UTC calendar day): `minecraft.guest_starts_per_day`;
 *  - a guest start must leave `minecraft.guest_free_slots` of the `max_running` slots free.
 */
class GuestStarts
{
    public static function perDay(): int
    {
        return max(0, (int) config('minecraft.guest_starts_per_day'));
    }

    public static function freeSlots(): int
    {
        return max(0, (int) config('minecraft.guest_free_slots'));
    }

    /**
     * Guest starts of this server since midnight (UTC), failed ones included: a VPS
     * that was created still cost money.
     */
    public static function usedToday(Server $server): int
    {
        return ServerRun::query()
            ->where('server_id', $server->id)
            ->whereNull('started_by')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * Called inside ServerLauncher::start()'s lock, after the "already running" and cap checks.
     *
     * @throws StartRefused
     */
    public static function check(Server $server, int $activeRuns, int $cap): void
    {
        $perDay = self::perDay();

        if (self::usedToday($server) >= $perDay) {
            throw StartRefused::guestBudget($server->name, $perDay);
        }

        if ($activeRuns >= $cap - self::freeSlots()) {
            throw StartRefused::guestSlot($server->name);
        }
    }

    /**
     * For the admin server form.
     *
     * @return array{per_day: int, free_slots: int, max_running: int, used_today: int|null}
     */
    public static function props(?Server $server): array
    {
        return [
            'per_day' => self::perDay(),
            'free_slots' => self::freeSlots(),
            'max_running' => (int) config('minecraft.max_running'),
            'used_today' => $server !== null ? self::usedToday($server) : null,
        ];
    }
}
