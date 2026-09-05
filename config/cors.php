<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ORIGIN', 'http://localhost:3000'))
    ))),

    'allowed_origins_patterns' => env('APP_ENV') === 'production'
        ? []
        : ['#^https?://(localhost|127\.0\.0\.1):\d+$#'],

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-Request-ID',
        'X-Client-Token-Retrieval-Ms',
        'Accept',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
