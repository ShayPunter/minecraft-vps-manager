<?php

namespace App\Console\Commands;

use App\Servers\ServerLauncher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled every minute: moves booting/starting runs on even when nobody has a page open.
 */
#[Signature('servers:advance')]
#[Description('Check starting servers and mark them online when Minecraft is up')]
class AdvanceServerRuns extends Command
{
    public function handle(ServerLauncher $launcher): int
    {
        $launcher->advanceActive(force: true);

        return self::SUCCESS;
    }
}
