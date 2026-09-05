<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
    'chat_completions_url' => 'https://api.openai.com/v1/chat/completions',
    'generation_model' => env('OPENAI_GENERATION_MODEL', 'gpt-4o-mini'),
    'validation_model' => env('OPENAI_VALIDATION_MODEL', 'gpt-4o-mini'),
    'legacy_model' => env('OPENAI_LEGACY_MODEL', 'gpt-3.5-turbo'),
];
