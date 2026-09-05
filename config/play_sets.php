<?php

return [
    'question_count' => (int) env('PLAY_SET_QUESTION_COUNT', 20),
    'questions_per_point_tier' => (int) env('PLAY_SET_QUESTIONS_PER_POINT_TIER', 4),
    'allowed_points' => [100, 200, 300, 400, 500],
    'source_excerpt_max_chars' => (int) env('PLAY_SET_SOURCE_EXCERPT_MAX_CHARS', 12000),
    'source_store_max_chars' => (int) env('PLAY_SET_SOURCE_STORE_MAX_CHARS', 100000),
    'generation_max_attempts' => (int) env('PLAY_SET_GENERATION_MAX_ATTEMPTS', 3),
    'question_bank_category_id' => env('PLAY_SET_QUESTION_CATEGORY_ID'),
];
