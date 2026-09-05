<?php

namespace App\Support;

final class QuestionConstants
{
    /** Internal AI-generation question types (not all are valid DB enum values). */
    public const AI_TYPES = ['single_answer', 'multiple_choice', 'true_false'];

    /** Canonical question-bank DB enum values. */
    public const BANK_TYPES = ['multiple_choice', 'true_false'];

    /** @deprecated Use AI_TYPES for generation; use BANK_TYPES for persisted bank rows. */
    public const TYPES = self::AI_TYPES;

    public const POINT_VALUES = [100, 200, 300, 400, 500];

    public const APPROVAL_STATUSES = ['pending', 'approved', 'rejected'];

    public const SOURCES = ['manual', 'textbook_ai', 'excel'];

    public const CHALLENGE_TYPES = ['school', 'family'];
}
