<?php

namespace App\Console\Commands;

use App\Servers\Watchdog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every minute (#5283).
 */
#[Signature('servers:watch')]
#[Description('Move starting servers on, give up on ones that never come online, stop idle ones')]
class WatchServers extends Command
{
    public function handle(Watchdog $watchdog): int
    {
        $watchdog->watch();

        return self::SUCCESS;
    }
}
