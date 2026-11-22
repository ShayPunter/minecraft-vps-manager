<?php

use App\Http\Controllers\Admin\DnsController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServerLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorldArchiveController;
use App\Http\Controllers\Admin\WorldStorageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RunLogIntakeController;
use App\Http\Controllers\ServerStartController;
use App\Http\Controllers\ServerStopController;
use App\Http\Controllers\VersionController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

// Public servers, which anyone may start.
Route::get('/', HomeController::class)->name('home');

// What's deployed. Outside the web group: no session, no cookies.
Route::get('/version', VersionController::class)
    ->withoutMiddleware('web')
    ->name('version');

// A run's VPS posts its log here (#5367), with the run's own token. No session, no cookies.
Route::post('runs/{run}/log', RunLogIntakeController::class)
    ->withoutMiddleware('web')
    ->middleware('throttle:run-log')
    ->where('run', '[0-9]+')
    ->name('runs.log.store');

// Guests may start public servers; the controller 404s private ones for them.
Route::post('servers/{server}/start', ServerStartController::class)
    ->middleware('throttle:server-start')
    ->name('servers.start');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    // Admins, or whoever started the run (the controller 404s everyone else).
    Route::post('servers/{server}/stop', ServerStopController::class)->name('servers.stop');
});

Route::middleware(['auth', EnsureUserIsAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::redirect('/', '/admin/users');
        Route::resource('users', UserController::class)->except(['show']);
        Route::resource('servers', ServerController::class)->except(['show']);
        Route::post('servers/{server}/stop', [ServerController::class, 'stop'])->name('servers.stop');
        // Each run's log (#5367).
        Route::get('servers/{server}/log', [ServerLogController::class, 'show'])->name('servers.log');
        Route::get('servers/{server}/log/lines', [ServerLogController::class, 'lines'])->name('servers.log.lines');

        // World storage (#5288).
        Route::get('world-storage', [WorldStorageController::class, 'edit'])->name('world-storage.edit');
        Route::put('world-storage', [WorldStorageController::class, 'update'])->name('world-storage.update');
        Route::post('world-storage/test', [WorldStorageController::class, 'test'])
            ->middleware('throttle:10,1')
            ->name('world-storage.test');
        Route::post('servers/{server}/world', [WorldArchiveController::class, 'store'])->name('servers.world.store');
        Route::get('servers/{server}/world/{archive}', [WorldArchiveController::class, 'show'])->name('servers.world.show');

        // DNS names for servers (#5338). Saving calls Cloudflare, so it's throttled.
        Route::get('dns', [DnsController::class, 'edit'])->name('dns.edit');
        Route::put('dns', [DnsController::class, 'update'])->middleware('throttle:10,1')->name('dns.update');
    });

require __DIR__.'/settings.php';
