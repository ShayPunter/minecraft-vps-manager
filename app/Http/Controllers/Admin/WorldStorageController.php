<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WorldStorageRequest;
use App\Models\WorldStorageSetting;
use App\WorldStorage\StorageSettings;
use App\WorldStorage\StorageUnavailable;
use App\WorldStorage\WorldStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → World storage (#5288): the SFTP, FTP(S) or S3-compatible (#5347) storage worlds
 * live on between sessions.
 * Saving always tests the connection first and pins the SFTP host key. The password and
 * private key are stored encrypted and never sent back to the browser.
 */
class WorldStorageController extends Controller
{
    public function edit(WorldStorage $storage): Response
    {
        $saved = $storage->saved();
        $source = $storage->source();

        /** @var array<string, mixed> $env */
        $env = config('world_storage.env');
        $fromEnv = $saved === null ? StorageSettings::fromEnv($env) : null;

        try {
            $storage->settings();
            $problem = null;
        } catch (StorageUnavailable $e) {
            $problem = $e->getMessage();
        }

        return Inertia::render('admin/world-storage/Edit', [
            'storage' => [
                'source' => $source,
                'driver' => $saved->driver ?? $fromEnv->driver ?? 'sftp',
                'host' => $saved->host ?? $fromEnv->host ?? '',
                'port' => $saved->port ?? $fromEnv->port ?? 22,
                'username' => $saved->username ?? $fromEnv->username ?? '',
                'root' => $saved->root ?? $fromEnv->root ?? 'minecraft-worlds',
                'ftps' => $saved->ftps ?? $fromEnv->ftps ?? true,
                'endpoint' => $saved->endpoint ?? $fromEnv->endpoint ?? '',
                'region' => $saved->region ?? $fromEnv->region ?? '',
                'bucket' => $saved->bucket ?? $fromEnv->bucket ?? '',
                'path_style' => $saved->path_style ?? $fromEnv->pathStyle ?? false,
                'has_password' => $saved !== null ? $saved->getRawOriginal('password') !== null : $fromEnv?->password !== null,
                'has_private_key' => $saved !== null ? $saved->getRawOriginal('private_key') !== null : $fromEnv?->privateKey !== null,
                'host_key_fingerprint' => StorageSettings::fingerprint($saved !== null ? $saved->host_key : $fromEnv?->hostKey),
                'updated_at' => $saved?->updated_at?->toIso8601String(),
            ],
            'problem' => $problem,
            'fake' => $storage->isFake(),
        ]);
    }

    /**
     * Test the form's settings without saving them.
     */
    public function test(WorldStorageRequest $request, WorldStorage $storage): RedirectResponse
    {
        $result = $storage->test($request->settings(), $this->pinnedHostKey($request), $request->boolean('trust_new_host_key'));

        $this->log($request, 'World storage connection tested', ['ok' => $result->ok]);

        Inertia::flash('toast', ['type' => $result->ok ? 'success' : 'error', 'message' => $result->message]);

        return back();
    }

    /**
     * Test, and save only if the test passes.
     */
    public function update(WorldStorageRequest $request, WorldStorage $storage): RedirectResponse
    {
        $settings = $request->settings();
        $result = $storage->test($settings, $this->pinnedHostKey($request), $request->boolean('trust_new_host_key'));

        if (! $result->ok) {
            $this->log($request, 'World storage not saved: connection test failed', ['ok' => false]);

            throw ValidationException::withMessages(['connection' => "Not saved. {$result->message}"]);
        }

        $row = $storage->saved() ?? new WorldStorageSetting;
        $row->driver = $settings->driver;
        $row->host = $settings->host;
        $row->port = $settings->port;
        $row->username = $settings->username;
        $row->password = $settings->password;
        $row->private_key = $settings->privateKey;
        $row->root = $settings->root;
        $row->ftps = $settings->ftps;
        $row->host_key = $settings->driver === 'sftp' ? $result->hostKey : null;
        $row->endpoint = $settings->isS3() ? $settings->endpoint : null;
        $row->region = $settings->isS3() ? $settings->region : null;
        $row->bucket = $settings->isS3() ? $settings->bucket : null;
        $row->path_style = $settings->isS3() && $settings->pathStyle;
        $row->updated_by = $request->user()?->id;
        $row->save();

        $this->log($request, 'World storage saved', ['ok' => true]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved. {$result->message}"]);

        return to_route('admin.world-storage.edit');
    }

    /**
     * The key saved for the same server. A new host or port is a new server, so its key
     * is taken as it comes (an admin is entering it right now).
     */
    private function pinnedHostKey(WorldStorageRequest $request): ?string
    {
        $saved = WorldStorageSetting::query()->first();

        if ($saved === null || $saved->driver !== 'sftp'
            || $saved->host !== $request->input('host') || $saved->port !== (int) $request->input('port')) {
            return null;
        }

        return $saved->host_key;
    }

    /**
     * Who did what, never a secret.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(Request $request, string $message, array $context): void
    {
        Log::info($message, [
            ...$context,
            'by' => $request->user()?->id,
            'driver' => $request->input('driver'),
            'host' => $request->input('host'),
            'port' => (int) $request->input('port'),
        ]);
    }
}
