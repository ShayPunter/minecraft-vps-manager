<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Servers\ServerLauncher;
use App\Servers\ServerPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The welcome page: public servers, which anyone may start (#5276).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, ServerLauncher $launcher): Response
    {
        $launcher->advanceActive();

        return Inertia::render('Welcome', [
            'servers' => ServerPresenter::list(Server::query()->where('is_public', true), $request->user()),
        ]);
    }
}
