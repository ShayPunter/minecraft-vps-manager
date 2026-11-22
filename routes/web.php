<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\VersionController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

// Servers marked public are listed here once servers exist (#5282); until then the list is empty.
Route::inertia('/', 'Welcome', ['servers' => []])->name('home');

// What's deployed. Outside the web group: no session, no cookies.
Route::get('/version', VersionController::class)
    ->withoutMiddleware('web')
    ->name('version');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth', EnsureUserIsAdmin::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::redirect('/', '/admin/users');
        Route::resource('users', UserController::class)->except(['show']);
    });

require __DIR__.'/settings.php';
