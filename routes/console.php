<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The watchdog (#5283): move starting servers on, give up on ones that never come online,
// and stop idle ones (world saved first). Runs whether anyone has a page open or not.
Schedule::command('servers:watch')->everyMinute()->withoutOverlapping();

// Delete VPSes no run knows about, retry failed stops, alert on very long runs.
Schedule::command('servers:sweep')->everyTenMinutes()->withoutOverlapping();

// Server logs (#5367) hold player names and IPs: keep them 14 days.
Schedule::command('servers:prune-logs')->daily()->withoutOverlapping();
