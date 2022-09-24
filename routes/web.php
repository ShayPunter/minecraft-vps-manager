<?php

use App\Http\Controllers\ServerProgressController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use phpseclib3\Net\SSH2;
use Symfony\Component\HttpClient\HttpClient;
use App\Models\ServerProgress;
use App\Models\Server;

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

Route::get('/startserver/{servername}', [\App\Http\Controllers\LinodeController::class, 'create_server'])->name('startserver');
Route::get('/check-server-status', [\App\Http\Controllers\ServerMonitorController::class, 'getOnlinePlayers'])->name('check-server-status');
Route::get('/serverprogress/{servername}', function ($servername) {
    $progress = ServerProgress::where('server_id', '=', $servername)->get()->first();

    if ($progress == null) {
        return response()->json(['error' => 'no server found']);
    }

    return response()->json(['ticker' => $progress->progress, 'ip_address' => $progress->ip_address]);
})->name('serverprogress');
Route::get('/serverstatus/{servername}', function($servername) {
    $serverstatus = Server::where('server_id', '=', $servername)->get()->first();

    if ($serverstatus == null) {
        return response()->json(['error' => 'no server found']);
    }

    return response()->json(Server::where('server_id', '=', $servername)->get()->first());
})->name('serverstatus');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');
});