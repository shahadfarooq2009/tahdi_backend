<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/api/auth/google/callback'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'generation_model' => env('OPENAI_GENERATION_MODEL', 'gpt-4o-mini'),
        'validation_model' => env('OPENAI_VALIDATION_MODEL', 'gpt-4o-mini'),
        'legacy_model' => env('OPENAI_LEGACY_MODEL', 'gpt-3.5-turbo'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Node legacy API (textbook / curriculum pipeline)
    |--------------------------------------------------------------------------
    |
    | Admin textbook routes are proxied to backend-node-legacy until the full
    | curriculum pipeline is ported to Laravel. Keep npm run dev running on :4000.
    |
    */
    'node_legacy' => [
        'enabled' => env('NODE_LEGACY_ENABLED', true),
        'url' => env('NODE_LEGACY_URL', 'http://127.0.0.1:4000'),
        'timeout' => (int) env('NODE_LEGACY_TIMEOUT', 300),
    ],

];
