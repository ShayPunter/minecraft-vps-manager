<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use App\Servers\Watchdog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every 10 minutes (#5283).
 */
#[Signature('servers:sweep')]
#[Description('Delete VPSes no server is using, retry failed stops, alert on long runs')]
class SweepServers extends Command
{
    public function handle(Watchdog $watchdog): int
    {
        $watchdog->sweep();
        QueueHeartbeat::dispatch();

        return self::SUCCESS;
    }
}
