<?php

namespace App\Jobs;

use App\Models\ServerRun;
use App\Servers\ServerLauncher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Puts the saved world on a run's VPS and starts Minecraft (#5288), queued once by
 * ServerLauncher::advance() when the VPS is up. One try: a VPS that isn't reachable yet is
 * queued again on the next check. Waiting for cloud-init plus a big download can take a
 * while, hence the timeout (the database queue's retry_after is above it).
 */
class PrepareServerRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1500;

    public function __construct(public readonly int $runId)
    {
        $this->afterCommit();
    }

    public function handle(ServerLauncher $launcher): void
    {
        $run = ServerRun::query()->find($this->runId);

        if ($run !== null) {
            $launcher->prepare($run);
        }
    }

    public function failed(?Throwable $e): void
    {
        $run = ServerRun::query()->find($this->runId);

        if ($run !== null && $run->prepared_at === null) {
            app(ServerLauncher::class)->retryPrepare($run);
        }
    }
}
