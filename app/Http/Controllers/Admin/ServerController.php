<?php

namespace App\Http\Controllers\Admin;

use App\Dns\Dns;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ServerStopController;
use App\Http\Requests\Admin\ServerRequest;
use App\Models\Server;
use App\Models\WorldArchive;
use App\Servers\ServerCatalog;
use App\Servers\ServerLauncher;
use App\Servers\ServerPresenter;
use App\WorldStorage\Archives;
use App\WorldStorage\StorageUnavailable;
use App\WorldStorage\WorldStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Servers: define Minecraft servers, and stop a running one by hand.
 */
class ServerController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/servers/Index', [
            'servers' => ServerPresenter::list(Server::query(), $request->user()),
            'maxRunning' => (int) config('minecraft.max_running'),
        ]);
    }

    public function create(ServerCatalog $catalog): Response
    {
        return Inertia::render('admin/servers/Create', [
            'catalog' => $catalog->formOptions(),
            'javaVersions' => config('minecraft.java_versions'),
            'dnsDomain' => app(Dns::class)->saved()?->domain,
        ]);
    }

    public function store(ServerRequest $request): RedirectResponse
    {
        $server = Server::query()->create($request->safe()->except('eula'));
        $this->recordEula($request, $server);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Created {$server->name}."]);

        return to_route('admin.servers.index');
    }

    public function edit(Server $server, ServerCatalog $catalog, WorldStorage $storage): Response
    {
        try {
            $storage->settings();
            $storageProblem = null;
        } catch (StorageUnavailable $e) {
            $storageProblem = $e->getMessage();
        }

        return Inertia::render('admin/servers/Edit', [
            'server' => [
                ...$server->only(['id', 'name', 'slug', 'description', 'is_public', 'icon_url', 'server_type', 'location', 'start_command', 'java_version', 'idle_minutes', 'subdomain']),
                'eula_accepted_at' => $server->eula_accepted_at?->toIso8601String(),
                'eula_accepted_by' => $server->eulaAcceptor?->name,
            ],
            'dnsDomain' => app(Dns::class)->saved()?->domain,
            'catalog' => $catalog->formOptions(),
            'javaVersions' => config('minecraft.java_versions'),
            'world' => [
                'archives' => $server->worldArchives()->with('uploader:id,name')->orderByDesc('id')->get()
                    ->map(fn (WorldArchive $archive): array => [
                        'id' => $archive->id,
                        'file_name' => $archive->fileName(),
                        'size' => $archive->size,
                        'sha256' => $archive->sha256,
                        'source' => $archive->source,
                        'uploaded_by' => $archive->uploader?->name,
                        'created_at' => $archive->created_at?->toIso8601String(),
                        'current' => $archive->id === $server->current_world_archive_id,
                    ])->all(),
                'storageProblem' => $storageProblem,
                'running' => $server->activeRun()->exists(),
                'uploadMaxMb' => (int) config('world_storage.upload_max_mb'),
                'keep' => (int) config('world_storage.keep'),
            ],
        ]);
    }

    public function update(ServerRequest $request, Server $server): RedirectResponse
    {
        $server->update($request->safe()->except('eula'));
        $this->recordEula($request, $server);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved {$server->name}. Changes apply from the next start."]);

        return to_route('admin.servers.index');
    }

    /**
     * Who accepted the Minecraft EULA for this server, and when (#5367). Ticking it again
     * keeps the first acceptance; unticking clears it.
     */
    private function recordEula(ServerRequest $request, Server $server): void
    {
        if ($request->boolean('eula') && $server->eula_accepted_at === null) {
            $server->eula_accepted_at = now();
            $server->eula_accepted_by = $request->user()?->id;
            $server->save();
            Log::info('Minecraft EULA accepted', ['server' => $server->slug, 'by' => $request->user()?->id]);
        } elseif (! $request->boolean('eula') && $server->eula_accepted_at !== null) {
            $server->eula_accepted_at = null;
            $server->eula_accepted_by = null;
            $server->save();
        }
    }

    /**
     * The saved worlds stay on the storage server unless `delete_archives` is ticked (#5288).
     */
    public function destroy(Request $request, Server $server, Archives $archives): RedirectResponse
    {
        if ($server->activeRun()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Stop {$server->name} before deleting it."]);

            return back();
        }

        $name = $server->name;
        $message = "Deleted {$name}.";

        if ($request->boolean('delete_archives') && $server->worldArchives()->exists()) {
            try {
                $failed = $archives->deleteAll($server);
            } catch (StorageUnavailable $e) {
                Inertia::flash('toast', ['type' => 'error', 'message' => "Nothing was deleted: the saved worlds couldn't be removed. {$e->getMessage()}"]);

                return back();
            }

            if ($failed !== []) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'Nothing was deleted: '.count($failed).' saved world(s) couldn\'t be removed from storage. Try again, or delete the server without them.']);

                return back();
            }

            $message = "Deleted {$name} and its saved worlds.";
        } elseif ($server->worldArchives()->exists()) {
            $message = "Deleted {$name}. Its saved worlds are still in storage, under {$server->slug}/.";
        }

        Log::info('Server deleted', ['server' => $server->slug, 'archives_deleted' => $request->boolean('delete_archives'), 'by' => $request->user()?->id]);
        $server->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.servers.index');
    }

    /**
     * Stop now: the world is saved, then the VPS is deleted (StopServerRun). With `force`,
     * only after a failed stop, the VPS is deleted without saving.
     */
    public function stop(Request $request, Server $server, ServerLauncher $launcher): RedirectResponse
    {
        return ServerStopController::respond($server, $launcher, 'admin', $request->boolean('force'));
    }
}
