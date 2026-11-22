<?php

// Where worlds live between sessions (#5288): one SFTP or FTP(S) server. Admin → World
// storage is the normal place to set it; these env values are only used while nothing has
// been saved there. Tests and E2E always use a local folder instead (App\WorldStorage\WorldStorage).
// `?:`, not env()'s default: an empty `X=` line in .env would skip the fallback.
return [
    'env' => [
        'driver' => env('WORLD_STORAGE_DRIVER') ?: 'sftp',
        'host' => env('WORLD_STORAGE_HOST') ?: null,
        'port' => is_numeric(env('WORLD_STORAGE_PORT')) ? (int) env('WORLD_STORAGE_PORT') : null,
        'username' => env('WORLD_STORAGE_USERNAME') ?: null,
        'password' => env('WORLD_STORAGE_PASSWORD') ?: null,
        // A file holding the OpenSSH private key (an env value can't hold several lines well).
        'private_key_path' => env('WORLD_STORAGE_PRIVATE_KEY_PATH') ?: null,
        'root' => env('WORLD_STORAGE_ROOT') ?: 'minecraft-worlds',
        // FTP only: TLS unless turned off, since plain FTP sends the password in the clear.
        'ftps' => env('WORLD_STORAGE_FTPS') === null || filter_var(env('WORLD_STORAGE_FTPS'), FILTER_VALIDATE_BOOLEAN),
        // SFTP only: the server's public host key ("ssh-ed25519 AAAA…"). Without it the env
        // settings aren't used: save them once in Admin → World storage to pin the key.
        'host_key' => env('WORLD_STORAGE_HOST_KEY') ?: null,
    ],

    // A local folder in place of the storage server (always in the testing and e2e environments).
    'fake' => (bool) env('WORLD_STORAGE_FAKE', false),
    'fake_root' => env('WORLD_STORAGE_FAKE_ROOT') ?: storage_path('app/private/world-storage-fake'),

    // How many archives each server keeps (the current one is always among them).
    'keep' => 3,

    // Largest world or modpack zip an admin may upload, in MB. PHP's upload_max_filesize and
    // post_max_size (and nginx's client_max_body_size) must allow it too.
    'upload_max_mb' => (int) (env('MVM_WORLD_UPLOAD_MAX_MB') ?: 4096),
];
