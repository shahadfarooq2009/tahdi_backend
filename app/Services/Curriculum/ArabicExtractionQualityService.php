<?php

namespace App\Services\Curriculum;

class ArabicExtractionQualityService
{
    public function __construct(
        private readonly ArabicPdfTextNormalizer $normalizer,
    ) {}

    /**
     * @return array{
     *   score: float,
     *   arabic_ratio: float,
     *   token_hits: int,
     *   word_continuity: float,
     *   acceptable: bool
     * }
     */
    public function assessPage(string $text, ?string $rawText = null): array
    {
        $rawText ??= $text;
        $normalized = ArabicTextService::normalizeArabicText($text);

        $tokenHits = 0;

        foreach ($this->meaningfulTokens() as $token) {
            if (str_contains($normalized, $token)) {
                $tokenHits++;
            }
        }

        $lines = preg_split('/\R+/u', trim($text)) ?: [];
        $lineScores = array_map(fn (string $line) => $this->normalizer->scoreLine($line), array_filter($lines));
        $avgLineScore = $lineScores === [] ? 0.0 : array_sum($lineScores) / count($lineScores);
        $arabicRatio = $this->normalizer->arabicLetterRatio($text);
        $wordContinuity = $this->wordContinuity($normalized);
        $improvement = $this->normalizer->scoreLine($text) - $this->normalizer->scoreLine($rawText);

        $score = round(
            ($arabicRatio * 0.2)
            + ($wordContinuity * 0.25)
            + (min(1.0, $tokenHits / 3) * 0.25)
            + ($avgLineScore * 0.3),
            4
        );

        $threshold = (float) config('textbook_extraction.min_page_quality', 0.42);

        return [
            'score' => $score,
            'arabic_ratio' => round($arabicRatio, 4),
            'token_hits' => $tokenHits,
            'word_continuity' => round($wordContinuity, 4),
            'line_score' => round($avgLineScore, 4),
            'rtl_improvement' => round($improvement, 4),
            'acceptable' => $score >= $threshold,
        ];
    }

    /**
     * @param  array<int, array{page_number: int, quality?: array<string, mixed>}>  $pages
     */
    public function frontMatterTrustworthy(array $pages, int $sampleSize = 20): bool
    {
        $sample = array_slice($pages, 0, $sampleSize);

        if ($sample === []) {
            return false;
        }

        $scores = array_map(
            fn (array $page) => $this->pageQualityScore($page),
            $sample
        );

        $average = array_sum($scores) / count($scores);
        $threshold = (float) config('textbook_extraction.front_matter_quality_threshold', 0.45);

        return $average >= $threshold;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function frontMatterAverageQuality(array $pages, int $sampleSize = 20): float
    {
        $sample = array_slice($pages, 0, $sampleSize);

        if ($sample === []) {
            return 0.0;
        }

        $scores = array_map(
            fn (array $page) => $this->pageQualityScore($page),
            $sample
        );

        return array_sum($scores) / count($scores);
    }

    /**
     * @return list<string>
     */
    private function meaningfulTokens(): array
    {
        return [
            'الوحده', 'الفصل', 'الدرس', 'الفهرس', 'المحتويات', 'الكتاب', 'الموضوع',
            'الثانويه', 'الثانوي', 'التربيه', 'التعليم', 'الاسلاميه',
        ];
    }

    private function wordContinuity(string $normalized): float
    {
        $words = array_values(array_filter(explode(' ', $normalized)));

        if ($words === []) {
            return 0.0;
        }

        $valid = 0;

        foreach ($words as $word) {
            if (mb_strlen($word) >= 3) {
                $valid++;
            }
        }

        return $valid / count($words);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public function pageQualityScore(array $page): float
    {
        $quality = $page['extraction_quality'] ?? $page['quality'] ?? null;

        if (is_array($quality)) {
            return (float) ($quality['score'] ?? 0);
        }

        if (is_int($quality) || is_float($quality)) {
            return (float) $quality;
        }

        return 0.0;
    }
}
