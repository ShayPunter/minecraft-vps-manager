<?php

namespace App\WorldStorage;

use App\Models\Server;
use App\Models\ServerRun;
use App\Models\User;
use App\Models\WorldArchive;
use App\Servers\ServerFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A server's world archives on the storage server (#5288). Each one is a file under
 * `<slug>/` in the base folder plus a WorldArchive row; the server's current one is what
 * the next start downloads. Only the newest `world_storage.keep` (3) are kept.
 */
class Archives
{
    public function __construct(private readonly WorldStorage $storage) {}

    /**
     * An admin's world or modpack zip becomes the server's current archive.
     *
     * @throws UnsafeArchive when the zip could unpack outside the server folder
     * @throws StorageUnavailable when storage isn't set up
     * @throws RuntimeException when the upload to storage fails
     */
    public function storeUpload(Server $server, UploadedFile $file, User $by): WorldArchive
    {
        $local = $file->getRealPath();

        if ($local === false) {
            throw new RuntimeException('The upload didn\'t arrive.');
        }

        ZipInspector::assertSafe($local);

        $size = (int) filesize($local);
        $sha256 = (string) hash_file('sha256', $local);
        $path = $this->newPath($server, 'upload.zip');
        $disk = $this->storage->disk();

        $stream = fopen($local, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The upload couldn\'t be read.');
        }

        try {
            $disk->writeStream($path, $stream);
        } catch (Throwable $e) {
            throw new RuntimeException("The upload to world storage failed: {$e->getMessage()}", previous: $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $stored = $disk->size($path);

        if ($stored !== $size) {
            $this->deleteFile($path);

            throw new RuntimeException("World storage has {$stored} bytes of the {$size} uploaded: nothing was changed.");
        }

        $archive = $this->record($server, $path, $size, $sha256, 'upload', null, $by, ServerFiles::fromZip($local));

        Log::info('World archive uploaded', ['server' => $server->slug, 'archive' => $archive->id, 'size' => $size, 'by' => $by->id]);

        return $archive;
    }

    /**
     * Make a stored file the server's current archive, then drop the oldest past the limit.
     */
    /**
     * @param  array<string, string>  $files  the world's own server.properties etc. (#5377, ServerFiles)
     */
    public function record(Server $server, string $path, int $size, string $sha256, string $source, ?ServerRun $run = null, ?User $by = null, array $files = []): WorldArchive
    {
        $archive = DB::transaction(function () use ($server, $path, $size, $sha256, $source, $run, $by, $files): WorldArchive {
            $archive = new WorldArchive;
            $archive->server_id = $server->id;
            $archive->path = $path;
            $archive->size = $size;
            $archive->sha256 = $sha256;
            $archive->source = $source;
            $archive->files = $files;
            $archive->server_run_id = $run?->id;
            $archive->uploaded_by = $by?->id;
            $archive->save();

            $server->current_world_archive_id = $archive->id;
            $server->save();

            return $archive;
        });

        $this->prune($server);

        return $archive;
    }

    /**
     * Keep the newest few (and always the current one). A file that won't delete is logged
     * and its row kept, so the next prune tries again.
     */
    public function prune(Server $server): void
    {
        $keep = max(1, (int) config('world_storage.keep'));

        $server->worldArchives()
            ->orderByDesc('id')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get()
            ->reject(fn (WorldArchive $archive): bool => $archive->id === $server->current_world_archive_id)
            ->each(function (WorldArchive $archive): void {
                if ($this->deleteFile($archive->path)) {
                    $archive->delete();
                }
            });
    }

    /**
     * Delete every archive of a server from storage (when an admin deletes the server and
     * ticks the box). Returns the paths that couldn't be deleted.
     *
     * @return list<string>
     */
    public function deleteAll(Server $server): array
    {
        $failed = [];

        foreach ($server->worldArchives()->get() as $archive) {
            if ($this->deleteFile($archive->path)) {
                $archive->delete();
            } else {
                $failed[] = $archive->path;
            }
        }

        $server->current_world_archive_id = null;
        $server->save();

        return $failed;
    }

    /**
     * @return resource
     *
     * @throws StorageUnavailable
     * @throws RuntimeException
     */
    public function readStream(WorldArchive $archive)
    {
        $stream = $this->storage->disk()->readStream($archive->path);

        if (! is_resource($stream)) {
            throw new RuntimeException("World storage has no file at {$archive->path}.");
        }

        return $stream;
    }

    public function newPath(Server $server, string $suffix): string
    {
        return $server->slug.'/'.now()->utc()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'-'.$suffix;
    }

    private function deleteFile(string $path): bool
    {
        try {
            $disk = $this->storage->disk();

            if ($disk->exists($path)) {
                $disk->delete($path);
            }

            return true;
        } catch (Throwable $e) {
            Log::warning("Couldn't delete world archive {$path}: {$e->getMessage()}");

            return false;
        }
    }
}
