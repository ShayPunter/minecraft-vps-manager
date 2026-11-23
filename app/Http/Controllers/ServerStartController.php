<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Models\Server;
use App\Models\User;
use App\Servers\ServerLauncher;
use App\Servers\StartRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * POST Start. Public servers can be started by anyone (#5276); private ones need a login
 * and look like they don't exist to guests. Link-only servers (#5462) start through their
 * share link (SharedServerController); here only admins may start them. Throttled by the
 * `server-start` limiter.
 */
class ServerStartController extends Controller
{
    public function __invoke(Request $request, Server $server, ServerLauncher $launcher): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        $hidden = match ($server->visibility) {
            Visibility::Public => false,
            Visibility::Private => $user === null,
            Visibility::Link => $user?->isAdmin() !== true,
        };

        if ($hidden) {
            abort(404);
        }

        return self::launch($request, $server, $launcher);
    }

    /**
     * Start it and answer with a toast; also used by the share link.
     */
    public static function launch(Request $request, Server $server, ServerLauncher $launcher): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

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
