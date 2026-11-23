<?php

namespace App\Console\Commands;

use App\Support\Heartbeat;
use App\Waker\WakerLoop;
use App\Waker\WakerReplies;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The waker (#5620): a Forge daemon on the app's own server, listening on Minecraft's port.
 * Stopped servers that wake on join have their names pointed here (Dns::syncParked()), so
 * the server list shows them as asleep and a join starts them.
 *
 * Like a queue worker it exits after `php artisan queue:restart` (Forge's deploy script runs
 * it), so the daemon comes back with the new code; and after an hour, to start fresh.
 */
#[Signature('servers:waker {--host=0.0.0.0 : Address to listen on} {--port= : Port (default: minecraft.waker_port, 25565)} {--stop-after=3600 : Seconds before exiting for a fresh start (0 = never)}')]
#[Description('Answer Minecraft pings and joins for stopped servers, and start them on a join')]
class RunWaker extends Command
{
    public function handle(WakerReplies $replies): int
    {
        $port = is_numeric($this->option('port')) ? (int) $this->option('port') : (int) config('minecraft.waker_port');
        $loop = new WakerLoop($replies);

        try {
            $address = $loop->listen((string) $this->option('host'), $port);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Waker listening on {$address}.");

        $stopAfter = (int) $this->option('stop-after');
        $startedAt = microtime(true);
        $restartSignal = Cache::get('illuminate:queue:restart');
        $nextBeat = 0.0;

        while (true) {
            $loop->tick(1.0);
            $now = microtime(true);

            if ($now >= $nextBeat) {
                Heartbeat::beat(Heartbeat::WAKER);
                $nextBeat = $now + 60;

                if (Cache::get('illuminate:queue:restart') !== $restartSignal) {
                    $this->info('Restart requested (queue:restart): exiting.');

                    break;
                }
            }

            if ($stopAfter > 0 && $now - $startedAt >= $stopAfter && $loop->connectionCount() === 0) {
                break;
            }
        }

        $loop->close();

        return self::SUCCESS;
    }
}
