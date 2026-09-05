<?php

namespace App\Services\Curriculum;

final class ArabicTextService
{
    public static function normalizeForComparison(?string $text): string
    {
        return mb_strtolower(self::normalizeArabicText($text));
    }

    public static function normalizeArabicText(?string $text): string
    {
        if (! is_string($text) || $text === '') {
            return '';
        }

        $normalized = str_replace("\u{0640}", '', $text);
        $normalized = preg_replace('/[\x{0622}\x{0623}\x{0625}]/u', "\u{0627}", $normalized) ?? $normalized;
        $normalized = str_replace("\u{0629}", "\u{0647}", $normalized);
        $normalized = str_replace("\u{0649}", "\u{064A}", $normalized);
        $normalized = preg_replace('/[^\x{0600}-\x{06FF}0-9A-Za-z\s.,:;!?()\-]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @return string[]
     */
    public static function extractSignificantTokens(?string $text): array
    {
        $stopWords = [
            'ما', 'هو', 'هي', 'في', 'من', 'على', 'الى', 'إلى', 'عن', 'مع', 'هل', 'كم',
            'لماذا', 'اشرح', 'عرّف', 'عرف', 'المفهوم', 'التعليمي', 'رقم', 'بقيمة',
            'بقيمه', 'نقطة', 'نقطه', 'الدرس', 'الوحدة', 'الوحده', 'الاولى', 'الاولي', 'سؤال',
        ];

        return array_values(array_filter(
            explode(' ', self::normalizeForComparison($text)),
            fn ($token) => strlen($token) > 2
                && ! in_array($token, $stopWords, true)
                && ! preg_match('/^\d+$/', $token)
                && ! preg_match('/^(lesson|unit)-\d+$/', $token)
        ));
    }

    public static function significantTokenSimilarity(?string $a, ?string $b): float
    {
        $leftTokens = self::extractSignificantTokens($a);
        $rightTokens = self::extractSignificantTokens($b);

        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $left = array_flip($leftTokens);
        $right = array_flip($rightTokens);
        $intersection = count(array_intersect_key($left, $right));

        return $intersection / max(count($left), count($right));
    }

    public static function textSimilarity(?string $a, ?string $b): float
    {
        $left = self::normalizeForComparison($a);
        $right = self::normalizeForComparison($b);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        $leftTokens = array_flip(array_filter(explode(' ', $left)));
        $rightTokens = array_flip(array_filter(explode(' ', $right)));

        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($leftTokens, $rightTokens));

        return $intersection / max(count($leftTokens), count($rightTokens));
    }
}
