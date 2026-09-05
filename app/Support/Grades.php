<?php

namespace App\Support;

final class Grades
{
    public const ALLOWED = [
        'grade_1', 'grade_2', 'grade_3', 'grade_4', 'grade_5', 'grade_6',
        'grade_7', 'grade_8', 'grade_9', 'grade_10', 'grade_11', 'grade_12',
    ];

    public static function normalize(?string $grade): ?string
    {
        if (! is_string($grade)) {
            return $grade;
        }

        $trimmed = trim($grade);

        if (preg_match('/^grade[_-]?(\d{1,2})$/i', $trimmed, $matches)) {
            return 'grade_'.(int) $matches[1];
        }

        return $trimmed;
    }

    public static function isValid(?string $grade): bool
    {
        return is_string($grade) && in_array(self::normalize($grade), self::ALLOWED, true);
    }
}
