<?php

namespace App\Jobs;

use App\Support\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued by servers:sweep and app:doctor: when a worker runs it, the queue is alive.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Heartbeat::beat(Heartbeat::QUEUE);
    }
}
