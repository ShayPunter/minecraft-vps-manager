<?php

namespace App\Jobs;

use App\Models\ServerRun;
use App\Servers\ServerLauncher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Creates the VPS for a run that ServerLauncher::start() made. One try only: a second
 * try after a timeout could create a second VPS.
 */
class ProvisionServerRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $runId)
    {
        $this->afterCommit();
    }

    public function handle(ServerLauncher $launcher): void
    {
        $run = ServerRun::query()->find($this->runId);

        if ($run !== null) {
            $launcher->provision($run);
        }
    }
}
