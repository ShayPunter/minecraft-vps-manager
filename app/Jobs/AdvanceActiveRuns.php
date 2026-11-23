<?php

namespace App\Jobs;

use App\Servers\ServerLauncher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Moves booting/starting runs on for a page view (#5752). The home page, the dashboard and a
 * share page used to call ServerLauncher::advanceActive() themselves, so every 5 s poll
 * waited on Hetzner, Cloudflare, the Minecraft port and sometimes SSH. They queue this
 * instead (ServerLauncher::advanceSoon()) and the next poll shows what it found.
 *
 * Unique: however many tabs are polling, one of these is queued or running at a time.
 * One try: the next poll (or servers:watch, every minute) queues another.
 */
class AdvanceActiveRuns implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** A provider stall can't hold the lock for long: Hetzner's client gives up after 30 s. */
    public int $uniqueFor = 60;

    public int $timeout = 120;

    public function handle(ServerLauncher $launcher): void
    {
        $launcher->advanceActive();
    }
}
