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
        // Every run makes its own SSH key pair (#5324), so nothing is set up by hand. This is
        // only where the hand-made key of the first deploy lived: a run started before #5324
        // still logs in with it to save its world. Nothing new is ever written there.
        'legacy_ssh_private_key_path' => storage_path('app/private/ssh/id_ed25519'),
        'fake' => (bool) env('HETZNER_FAKE', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
