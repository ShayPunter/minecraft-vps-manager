<?php

use App\Models\Server;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Server management routes
Route::prefix('/server')->group(function () {
    Route::get('/{servername}', [\App\Http\Controllers\LinodeController::class, 'get_server'])->name('get-server');
    Route::get('/provision/{servername}', [\App\Http\Controllers\LinodeController::class, 'create_server'])->name('provision-server');
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
})->name('get-auth-user');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
});

// Admin Routes
Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified', 'isadmin',
])->group(function () {
    Route::get('/admin/dashboard', function () {
        return Inertia::render('Admin/Dashboard');
    })->name('admin-dashboard');
    Route::get('/admin/user', function () {
        return Inertia::render('Admin/Users/index');
    })->name('users');
    Route::get('/admin/user/create', function () {
        return Inertia::render('Admin/Users/new');
    })->name('create-user');
    Route::get('/admin/user/edit/{id}', function ($id) {
        return Inertia::render('Admin/Users/edit', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
            'editingUser' => $id,
        ]);
    })->name('edit-user');
});

// For local development testing
if (env('APP_ENV') == 'local') {
    Route::get('/test', function () {
        return Inertia::render('Test', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    });
}
