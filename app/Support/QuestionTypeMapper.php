<?php

namespace App\Support;

use App\Exceptions\ValidationException;

/**
 * Maps AI-generation question types to canonical question-bank DB enum values.
 *
 * AI pipeline uses internal terminology (e.g. single_answer).
 * PostgreSQL question_type enum currently accepts only: multiple_choice, true_false.
 */
final class QuestionTypeMapper
{
    /**
     * @var array<string, string>
     */
    private const AI_TO_BANK = [
        'single_answer' => 'multiple_choice',
        'multiple_choice' => 'multiple_choice',
        'true_false' => 'true_false',
    ];

    /**
     * @return string[]
     */
    public static function bankTypes(): array
    {
        return ['multiple_choice', 'true_false'];
    }

    /**
     * @return string[]
     */
    public static function aiTypes(): array
    {
        return array_keys(self::AI_TO_BANK);
    }

    public static function toBankType(string $aiType): string
    {
        $normalized = strtolower(trim($aiType));

        if (! array_key_exists($normalized, self::AI_TO_BANK)) {
            throw new ValidationException(
                'Unsupported AI question_type for promotion: '.$aiType
            );
        }

        return self::AI_TO_BANK[$normalized];
    }

    public static function isSupportedAiType(string $aiType): bool
    {
        return array_key_exists(strtolower(trim($aiType)), self::AI_TO_BANK);
    }
}
