<?php

namespace App\Support;

final class ArabicGradeMapper
{
    /** @var array<string, string> */
    private const GRADE_ARABIC_TO_DB = [
        'الصف الأول' => 'grade1',
        'الصف الثاني' => 'grade2',
        'الصف الثالث' => 'grade3',
        'الصف الرابع' => 'grade4',
        'الصف الخامس' => 'grade5',
        'الصف السادس' => 'grade6',
        'صف اول إعدادي' => 'grade7',
        'صف أول إعدادي' => 'grade7',
        'صف ثاني إعدادي' => 'grade8',
        'صف ثالث إعدادي' => 'grade9',
        'أول ثانوي' => 'grade10',
        'ثاني ثانوي' => 'grade11',
        'ثالث ثانوي' => 'grade12',
    ];

    /** @var array<string, string> */
    private const STAGE_ARABIC_TO_DB = [
        'المرحلة الإبتدائية' => 'primary',
        'المرحلة الاعدادية' => 'middle',
        'المرحلة الثانوية' => 'high',
    ];

    /** @var array<string, string> */
    private const TEXTBOOK_STAGE_ARABIC = [
        'المرحلة الإبتدائية' => 'ابتدائي',
        'المرحلة الاعدادية' => 'متوسط',
        'المرحلة الثانوية' => 'ثانوي',
    ];

    public static function gradeToDatabase(?string $arabicGrade): ?string
    {
        if (! is_string($arabicGrade) || trim($arabicGrade) === '') {
            return null;
        }

        $trimmed = trim($arabicGrade);

        if (isset(self::GRADE_ARABIC_TO_DB[$trimmed])) {
            return self::GRADE_ARABIC_TO_DB[$trimmed];
        }

        return Grades::normalize($trimmed);
    }

    public static function stageToDatabase(?string $arabicStage): ?string
    {
        if (! is_string($arabicStage) || trim($arabicStage) === '') {
            return null;
        }

        return self::STAGE_ARABIC_TO_DB[trim($arabicStage)] ?? trim($arabicStage);
    }

    public static function textbookStageFromArabic(?string $arabicStage): ?string
    {
        if (! is_string($arabicStage) || trim($arabicStage) === '') {
            return null;
        }

        return self::TEXTBOOK_STAGE_ARABIC[trim($arabicStage)] ?? trim($arabicStage);
    }
}
