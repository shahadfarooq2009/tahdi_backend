<?php

namespace App\Support;

/**
 * Normalizes textbook/question grades to canonical question-bank values.
 *
 * Textbooks may store numeric grades (e.g. "7") while subject_grades uses "grade7".
 */
final class QuestionGradeMapper
{
    public static function toBankGrade(?string $grade): ?string
    {
        if ($grade === null || trim($grade) === '') {
            return null;
        }

        $normalized = trim($grade);

        if (preg_match('/^grade\d+$/i', $normalized) === 1) {
            return strtolower($normalized);
        }

        if (preg_match('/^\d+$/', $normalized) === 1) {
            return 'grade'.$normalized;
        }

        return $normalized;
    }
}
