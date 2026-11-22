<?php

use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ServerStartController;
use App\Http\Controllers\VersionController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

// Public servers, which anyone may start.
Route::get('/', HomeController::class)->name('home');

// What's deployed. Outside the web group: no session, no cookies.
Route::get('/version', VersionController::class)
    ->withoutMiddleware('web')
    ->name('version');

// Guests may start public servers; the controller 404s private ones for them.
Route::post('servers/{server}/start', ServerStartController::class)
    ->middleware('throttle:server-start')
    ->name('servers.start');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

Route::middleware(['auth', EnsureUserIsAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::redirect('/', '/admin/users');
        Route::resource('users', UserController::class)->except(['show']);
        Route::resource('servers', ServerController::class)->except(['show']);
        Route::post('servers/{server}/stop', [ServerController::class, 'stop'])->name('servers.stop');
    });

require __DIR__.'/settings.php';
