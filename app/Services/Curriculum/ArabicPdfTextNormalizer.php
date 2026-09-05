<?php

namespace App\Services\Curriculum;

/**
 * Repairs common Arabic PDF text extraction issues: visual-order glyphs,
 * reversed words, and publisher footer noise.
 */
class ArabicPdfTextNormalizer
{
    /** @var list<string> */
    private const STRUCTURE_TOKENS = [
        'الوحدة', 'الوحده', 'الفصل', 'الدرس', 'الفهرس', 'المحتويات', 'المحتوى',
        'الكتاب', 'المقدمة', 'الباب', 'الموضوع', 'الصفحة', 'صفحة',
        'تربية', 'اسلامية', 'الاسلامية', 'الإسلامية', 'الثانوية', 'الثانوي',
        'الاولى', 'الاولي', 'الثانية', 'الثالثة', 'الرابعة', 'الخامسة',
        'وزارة', 'التعليم', 'التربية', 'المملكة', 'البحرين',
    ];

    public function normalizePageText(string $text): string
    {
        $text = $this->stripPublisherNoise($text);

        $lines = preg_split('/\R+/u', $text) ?: [];
        $repaired = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || $this->isNoiseLine($line)) {
                continue;
            }

            $repaired[] = $this->repairLine($line);
        }

        $joined = trim(implode("\n", $repaired));

        return $this->repairPageBlock($joined);
    }

    public function repairPageBlock(string $text): string
    {
        if (! $this->containsArabic($text)) {
            return $text;
        }

        $candidates = [
            $text,
            $this->reverseGraphemes($text),
            $this->reverseWordOrder($text),
            $this->reverseWordOrderAndArabicWords($text),
        ];

        $best = $text;
        $bestScore = $this->scoreLine($text);

        foreach ($candidates as $candidate) {
            $score = $this->scoreLine($candidate);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    public function stripPublisherNoise(string $text): string
    {
        $patterns = [
            '/\.indd\s+\d+.*$/mi',
            '/Deen\d+-\d+-\d+\.indd.*$/mi',
            '/\b\d{1,2}\/\d{1,2}\/\d{2,4}\s+\d{1,2}:\d{2}\s*(?:AM|PM)?\b/ui',
            '/\x{00AD}/u',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }

        return $text;
    }

    public function repairLine(string $line): string
    {
        if (! $this->containsArabic($line)) {
            return $line;
        }

        $candidates = [
            $line,
            $this->reverseGraphemes($line),
            $this->reverseWordOrder($line),
            $this->reverseWordOrderAndArabicWords($line),
            $this->reverseArabicWordsOnly($line),
        ];

        $best = $line;
        $bestScore = $this->scoreLine($line);

        foreach ($candidates as $candidate) {
            $score = $this->scoreLine($candidate);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    public function scoreLine(string $line): float
    {
        $normalized = ArabicTextService::normalizeArabicText($line);

        if ($normalized === '') {
            return 0.0;
        }

        $tokenHits = 0;

        foreach (self::STRUCTURE_TOKENS as $token) {
            if (str_contains($normalized, ArabicTextService::normalizeArabicText($token))) {
                $tokenHits++;
            }
        }

        $arabicRatio = $this->arabicLetterRatio($line);
        $wordContinuity = $this->wordContinuityScore($normalized);
        $tokenScore = min(1.0, $tokenHits / 4);

        return round(($arabicRatio * 0.25) + ($wordContinuity * 0.35) + ($tokenScore * 0.4), 4);
    }

    public function arabicLetterRatio(string $text): float
    {
        preg_match_all('/[\x{0600}-\x{06FF}]/u', $text, $arabic);
        preg_match_all('/\S/u', $text, $tokens);

        $arabicCount = count($arabic[0] ?? []);
        $tokenCount = count($tokens[0] ?? []);

        if ($tokenCount === 0) {
            return 0.0;
        }

        return min(1.0, $arabicCount / max(1, mb_strlen(preg_replace('/\s+/u', '', $text) ?? '')));
    }

    public function containsArabic(string $text): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }

    public function detectPrintedPageNumber(string $text): ?int
    {
        $lines = preg_split('/\R+/u', trim($text)) ?: [];

        foreach (array_slice($lines, 0, 3) as $line) {
            $line = trim((string) $line);

            if (preg_match('/^(\d{1,3})$/u', $line, $match)) {
                $page = (int) $match[1];

                if ($page >= 1 && $page <= 999) {
                    return $page;
                }
            }
        }

        return null;
    }

    private function isNoiseLine(string $line): bool
    {
        if (preg_match('/^Deen\d+/i', $line)) {
            return true;
        }

        if (preg_match('/\.indd\b/i', $line)) {
            return true;
        }

        return mb_strlen($line) <= 2 && preg_match('/^\d+$/u', $line);
    }

    private function reverseGraphemes(string $text): string
    {
        preg_match_all('/\X/u', $text, $matches);

        return implode('', array_reverse($matches[0] ?? []));
    }

    private function reverseWordOrder(string $line): string
    {
        $words = preg_split('/\s+/u', trim($line)) ?: [];

        return implode(' ', array_reverse($words));
    }

    private function reverseWordOrderAndArabicWords(string $line): string
    {
        $words = preg_split('/\s+/u', trim($line)) ?: [];
        $words = array_reverse($words);

        return implode(' ', array_map(function (string $word): string {
            return $this->containsArabic($word) ? $this->reverseGraphemes($word) : $word;
        }, $words));
    }

    private function reverseArabicWordsOnly(string $line): string
    {
        $words = preg_split('/\s+/u', trim($line)) ?: [];

        return implode(' ', array_map(function (string $word): string {
            return $this->repairArabicWord($word);
        }, $words));
    }

    private function repairArabicWord(string $word): string
    {
        if (! $this->containsArabic($word)) {
            return $word;
        }

        $reversed = $this->reverseGraphemes($word);

        return $this->scoreWord($reversed) > $this->scoreWord($word) ? $reversed : $word;
    }

    private function scoreWord(string $word): float
    {
        $normalized = ArabicTextService::normalizeArabicText($word);

        if ($normalized === '' || mb_strlen($normalized) < 2) {
            return 0.0;
        }

        $score = 0.0;

        foreach (self::STRUCTURE_TOKENS as $token) {
            $token = ArabicTextService::normalizeArabicText($token);

            if ($token !== '' && str_contains($token, $normalized)) {
                $score += 1.5;
            }

            if ($token !== '' && str_contains($normalized, $token)) {
                $score += 1.0;
            }
        }

        if (preg_match('/^[\x{0600}-\x{06FF}]{2,}$/u', $normalized)) {
            $score += 0.2;
        }

        return $score;
    }

    private function wordContinuityScore(string $normalized): float
    {
        $words = array_values(array_filter(explode(' ', $normalized)));

        if ($words === []) {
            return 0.0;
        }

        $valid = 0;

        foreach ($words as $word) {
            if (mb_strlen($word) >= 3 && preg_match('/^[\x{0600}-\x{06FF}0-9]+$/u', $word)) {
                $valid++;
            }
        }

        return $valid / count($words);
    }
}
