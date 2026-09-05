<?php

namespace App\Support;

class Utf8Text
{
    public static function sanitize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            $text = is_string($converted) ? $converted : $text;
        }

        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $clean = preg_replace('/\xEF\xBF\xBD/u', '', $clean) ?? $clean;

        return trim($clean);
    }
}
