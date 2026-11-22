<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Servers\ServerLauncher;
use App\Servers\ServerPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signed-in users see every server, private ones too, and can start any that's stopped.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ServerLauncher $launcher): Response
    {
        $launcher->advanceActive();

        return Inertia::render('Dashboard', [
            'servers' => ServerPresenter::list(Server::query(), $request->user()),
        ]);
    }
}
