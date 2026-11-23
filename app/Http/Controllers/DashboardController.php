<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Models\Server;
use App\Models\User;
use App\Servers\ServerLauncher;
use App\Servers\ServerPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signed-in users see every server, private ones too, and can start any that's stopped.
 * Link-only servers (#5462) are left out for players: they open through their share link.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ServerLauncher $launcher): Response
    {
        /** @var User $user */
        $user = $request->user();
        $launcher->advanceActive();

        return Inertia::render('Dashboard', [
            'servers' => ServerPresenter::list(
                Server::query()->when(! $user->isAdmin(), fn ($query) => $query->where('visibility', '!=', Visibility::Link)),
                $user,
            ),
        ]);
    }
}
