<?php

namespace App\Console\Commands;

use App\Servers\RunLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled daily (#5367): run logs hold player names and IPs, so they don't stay forever.
 */
#[Signature('servers:prune-logs')]
#[Description('Delete server log lines older than the retention period')]
class PruneRunLogs extends Command
{
    public function handle(RunLog $log): int
    {
        $deleted = $log->prune();
        $this->info("Deleted {$deleted} log lines older than ".RunLog::RETENTION_DAYS.' days.');

        return self::SUCCESS;
    }
}
