<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Active AI Provider
    |--------------------------------------------------------------------------
    |
    | Supported: openai, gemini
    |
    */
    'provider' => env('AI_PROVIDER', 'openai'),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'chat_completions_url' => 'https://api.openai.com/v1/chat/completions',
        'generation_model' => env('OPENAI_GENERATION_MODEL', 'gpt-4o-mini'),
        'validation_model' => env('OPENAI_VALIDATION_MODEL', 'gpt-4o-mini'),
        'legacy_model' => env('OPENAI_LEGACY_MODEL', 'gpt-3.5-turbo'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'generation_model' => env('GEMINI_GENERATION_MODEL', 'gemini-2.0-flash'),
        'validation_model' => env('GEMINI_VALIDATION_MODEL', 'gemini-2.0-flash'),
        'legacy_model' => env('GEMINI_LEGACY_MODEL', 'gemini-2.0-flash'),
    ],
];
