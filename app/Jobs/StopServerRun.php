<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Models\ServerRun;
use App\Servers\ServerLauncher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Saves the world and deletes the VPS for a run that ServerLauncher::requestStop() marked
 * "stopping". One try; a big modpack can take minutes to save, hence the long timeout
 * (the database queue's retry_after is above it).
 */
class StopServerRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $runId,
        public readonly bool $force = false,
    ) {
        $this->afterCommit();
    }

    public function handle(ServerLauncher $launcher): void
    {
        $run = ServerRun::query()->find($this->runId);

        if ($run !== null) {
            $launcher->stop($run, $this->force);
        }
    }

    public function failed(?Throwable $e): void
    {
        $run = ServerRun::query()->find($this->runId);

        if ($run !== null && $run->status === RunStatus::Stopping) {
            app(ServerLauncher::class)->stopFailed($run, 'The stop job crashed: '.($e?->getMessage() ?? 'unknown error'));
        }
    }
}
