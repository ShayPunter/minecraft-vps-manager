<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\User;
use App\Servers\ServerLauncher;
use App\Servers\StartRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * POST Start. Public servers can be started by anyone (#5276); private ones need a login
 * and look like they don't exist to guests. Throttled by the `server-start` limiter.
 */
class ServerStartController extends Controller
{
    public function __invoke(Request $request, Server $server, ServerLauncher $launcher): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $server->is_public && $user === null) {
            abort(404);
        }

        try {
            $launcher->start($server, $user, $request->ip());
        } catch (StartRefused $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        $server->load('latestRun');
        $failed = $server->latestRun?->error;

        Inertia::flash('toast', $failed !== null
            ? ['type' => 'error', 'message' => "{$server->name} couldn't start. {$failed}"]
            : ['type' => 'success', 'message' => "Starting {$server->name}. It takes a few minutes."]);

        return back();
    }
}
