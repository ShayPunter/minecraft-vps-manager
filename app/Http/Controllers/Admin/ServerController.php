<?php

namespace App\Http\Controllers\Admin;

use App\Cloud\CloudException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerRequest;
use App\Models\Server;
use App\Servers\ServerCatalog;
use App\Servers\ServerLauncher;
use App\Servers\ServerPresenter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Servers: define Minecraft servers, and stop a running one by hand.
 */
class ServerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/servers/Index', [
            'servers' => ServerPresenter::list(Server::query()),
            'maxRunning' => (int) config('minecraft.max_running'),
        ]);
    }

    public function create(ServerCatalog $catalog): Response
    {
        return Inertia::render('admin/servers/Create', [
            'catalog' => $catalog->formOptions(),
            'javaVersions' => config('minecraft.java_versions'),
        ]);
    }

    public function store(ServerRequest $request): RedirectResponse
    {
        $server = Server::query()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Created {$server->name}."]);

        return to_route('admin.servers.index');
    }

    public function edit(Server $server, ServerCatalog $catalog): Response
    {
        return Inertia::render('admin/servers/Edit', [
            'server' => $server->only(['id', 'name', 'slug', 'description', 'is_public', 'icon_url', 'server_type', 'location', 'start_command', 'java_version']),
            'catalog' => $catalog->formOptions(),
            'javaVersions' => config('minecraft.java_versions'),
        ]);
    }

    public function update(ServerRequest $request, Server $server): RedirectResponse
    {
        $server->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved {$server->name}. Changes apply from the next start."]);

        return to_route('admin.servers.index');
    }

    public function destroy(Server $server): RedirectResponse
    {
        if ($server->activeRun()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Stop {$server->name} before deleting it."]);

            return back();
        }

        $name = $server->name;
        $server->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Deleted {$name}."]);

        return to_route('admin.servers.index');
    }

    /**
     * Delete the VPS now. Until the watchdog (#5283) exists, this is how a server stops.
     */
    public function stop(Server $server, ServerLauncher $launcher): RedirectResponse
    {
        $run = $server->activeRun;

        if ($run === null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => "{$server->name} isn't running."]);

            return back();
        }

        try {
            $launcher->stop($run);
        } catch (CloudException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Couldn't delete the VPS: {$e->getMessage()}"]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Stopped {$server->name}. The VPS is deleted."]);

        return back();
    }
}
