<?php

namespace App\Support;

/**
 * Textbook processing lifecycle statuses.
 *
 * Upload → extract → analyze → unit review → approve → generate questions → ready
 */
final class TextbookProcessingStatus
{
    public const UPLOADED = 'uploaded';

    public const QUEUED = 'queued';

    public const EXTRACTING = 'extracting';

    public const ANALYZING_CONTENTS = 'analyzing_contents';

    public const UNITS_DETECTED = 'units_detected';

    public const AWAITING_UNIT_REVIEW = 'awaiting_unit_review';

    public const MANUAL_STRUCTURE_REQUIRED = 'manual_structure_required';

    public const UNITS_APPROVED = 'units_approved';

    public const GENERATING_QUESTIONS = 'generating_questions';

    public const AWAITING_QUESTION_REVIEW = 'awaiting_question_review';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::UPLOADED,
            self::QUEUED,
            self::EXTRACTING,
            self::ANALYZING_CONTENTS,
            self::UNITS_DETECTED,
            self::AWAITING_UNIT_REVIEW,
            self::MANUAL_STRUCTURE_REQUIRED,
            self::UNITS_APPROVED,
            self::GENERATING_QUESTIONS,
            self::AWAITING_QUESTION_REVIEW,
            self::READY,
            self::FAILED,
        ];
    }

    /** Statuses where background jobs may still be running. */
    public static function isActive(string $status): bool
    {
        return in_array($status, [
            self::QUEUED,
            self::EXTRACTING,
            self::ANALYZING_CONTENTS,
            self::UNITS_DETECTED,
            self::UNITS_APPROVED,
            self::GENERATING_QUESTIONS,
        ], true);
    }

    /** Map legacy DB values to the current vocabulary. */
    public static function normalize(?string $status): string
    {
        return match ($status) {
            'processing' => 'processing',
            'review_required' => self::AWAITING_UNIT_REVIEW,
            'queued' => self::QUEUED,
            'detecting_units' => self::UNITS_DETECTED,
            default => $status ?? self::UPLOADED,
        };
    }
}
