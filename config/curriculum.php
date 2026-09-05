<?php

use App\Support\QuestionConstants;

return [
    'target_questions_per_unit' => (int) env('TARGET_QUESTIONS_PER_UNIT', 60),
    'questions_per_review_set' => (int) env('QUESTIONS_PER_REVIEW_SET', 20),
    'min_playable_review_set_size' => (int) env('MIN_PLAYABLE_REVIEW_SET_SIZE', 15),
    'questions_per_point_tier' => (int) env('QUESTIONS_PER_POINT_TIER', 4),
    'duplicate_similarity_threshold' => (float) env('DUPLICATE_SIMILARITY_THRESHOLD', 0.85),
    'cross_set_duplicate_threshold' => (float) env('CROSS_SET_DUPLICATE_THRESHOLD', 0.8),
    'answer_similarity_threshold' => (float) env('ANSWER_SIMILARITY_THRESHOLD', 0.9),
    'max_generation_attempts_multiplier' => (int) env('MAX_GENERATION_ATTEMPTS_MULTIPLIER', 3),
    'point_values' => QuestionConstants::POINT_VALUES,
];
