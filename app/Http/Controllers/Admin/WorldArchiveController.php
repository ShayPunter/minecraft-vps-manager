<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Models\WorldArchive;
use App\WorldStorage\Archives;
use App\WorldStorage\StorageUnavailable;
use App\WorldStorage\UnsafeArchive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A server's world archives on the storage server (#5288): an admin uploads a world or
 * modpack zip (it becomes what the next start downloads) and downloads any kept archive,
 * streamed through the app so the storage server is never exposed.
 */
class WorldArchiveController extends Controller
{
    public function store(Request $request, Server $server, Archives $archives): RedirectResponse
    {
        $request->validate([
            'archive' => ['required', 'file', 'extensions:zip', 'max:'.((int) config('world_storage.upload_max_mb') * 1024)],
        ], [
            'archive.extensions' => 'Upload a .zip file.',
            'archive.max' => 'That zip is over the '.config('world_storage.upload_max_mb').' MB limit.',
            'archive.uploaded' => 'The upload didn\'t arrive: it may be over the server\'s upload limit.',
        ]);

        if ($server->activeRun()->exists()) {
            throw ValidationException::withMessages(['archive' => "Stop {$server->name} first: its stop would save over the upload."]);
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $archive = $archives->storeUpload($server, $request->file('archive'), $user);
        } catch (UnsafeArchive|StorageUnavailable|RuntimeException $e) {
            throw ValidationException::withMessages(['archive' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Uploaded {$archive->fileName()}. {$server->name} starts with it next time."]);

        return back();
    }

    public function show(Request $request, Server $server, WorldArchive $archive, Archives $archives): StreamedResponse|RedirectResponse
    {
        abort_unless($archive->server_id === $server->id, 404);

        try {
            $stream = $archives->readStream($archive);
        } catch (StorageUnavailable|RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => "Couldn't download it: {$e->getMessage()}"]);

            return back();
        }

        Log::info('World archive downloaded', ['server' => $server->slug, 'archive' => $archive->id, 'by' => $request->user()?->id]);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, $archive->fileName(), [
            'Content-Type' => str_ends_with($archive->path, '.zip') ? 'application/zip' : 'application/gzip',
            'Content-Length' => (string) $archive->size,
        ]);
    }
}
