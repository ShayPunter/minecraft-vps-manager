<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Models\Server;
use App\Models\User;
use App\Servers\ServerLauncher;
use App\Servers\ServerPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * POST Stop from the dashboard or home page (#5283): admins, and whoever started the run.
 * Everyone else gets a 404, as if there were nothing to stop. Admin → Servers uses
 * Admin\ServerController::stop, which can also force a delete after a failed stop.
 */
class ServerStopController extends Controller
{
    public function __invoke(Request $request, Server $server, ServerLauncher $launcher): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $run = $server->activeRun;

        if ($run === null || ! ServerPresenter::mayStop($run, $user)) {
            abort(404);
        }

        return self::respond($server, $launcher, $user->isAdmin() ? 'admin' : 'starter');
    }

    public static function respond(Server $server, ServerLauncher $launcher, string $reason, bool $force = false): RedirectResponse
    {
        $run = $server->activeRun;

        if ($run === null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => "{$server->name} isn't running."]);

            return back();
        }

        if (! $launcher->requestStop($run, $reason, $force)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => $run->status === RunStatus::Provisioning
                ? "{$server->name} is still being created. Stop it once it's booting."
                : "{$server->name} is already stopping."]);

            return back();
        }

        // The queue may already have finished the stop (sync queue, or a fast worker).
        $run->refresh();

        Inertia::flash('toast', match ($run->status) {
            RunStatus::Stopped => ['type' => 'success', 'message' => $force
                ? "Deleted the VPS of {$server->name} without saving the world."
                : "Stopped {$server->name}. The world is saved and the VPS is deleted."],
            RunStatus::StopFailed => ['type' => 'error', 'message' => "{$server->name} couldn't be stopped. ".ServerPresenter::errorFor($run->error, self::viewer())],
            default => ['type' => 'success', 'message' => "Stopping {$server->name}. The world is saved first, then the VPS is deleted."],
        });

        return back();
    }

    private static function viewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
