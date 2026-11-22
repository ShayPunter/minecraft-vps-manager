<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Hetzner Cloud: where the Minecraft VPSes run (App\Cloud). Tests and E2E always use
    // the fake, whatever this says (AppServiceProvider).
    'hetzner' => [
        'token' => env('HETZNER_TOKEN'),
        'base_url' => 'https://api.hetzner.cloud/v1',
        // The ed25519 public key registered with Hetzner; the app logs in to its VPSes with
        // the private half next to it. Make it with: ssh-keygen -t ed25519 -N "" -f <path without .pub>
        // `?:`, not env()'s default: an empty `HETZNER_SSH_PUBLIC_KEY=` line would skip it.
        'ssh_public_key_path' => env('HETZNER_SSH_PUBLIC_KEY') ?: storage_path('app/private/ssh/id_ed25519.pub'),
        // The private half, used to log in to the VPS as root and stop Minecraft cleanly (#5283).
        'ssh_private_key_path' => env('HETZNER_SSH_PRIVATE_KEY') ?: storage_path('app/private/ssh/id_ed25519'),
        'fake' => (bool) env('HETZNER_FAKE', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
