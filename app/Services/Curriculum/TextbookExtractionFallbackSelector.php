<?php

namespace App\Services\Curriculum;

class TextbookExtractionFallbackSelector
{
    public function __construct(
        private readonly ArabicExtractionQualityService $quality,
    ) {}

    /**
     * When front matter is untrustworthy, OCR must not depend on TOC keywords.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return list<int>
     */
    public function selectOcrPages(array $pages, int $frontLimit, int $maxOcr, bool $fallbackRequired = false): array
    {
        $minQuality = (float) config('textbook_extraction.min_page_quality', 0.42);

        $frontPages = array_values(array_filter(
            $pages,
            fn (array $page) => (int) ($page['page_number'] ?? 0) <= $frontLimit
        ));

        if ($frontPages === []) {
            return [];
        }

        usort($frontPages, fn (array $a, array $b) => $this->quality->pageQualityScore($a)
            <=> $this->quality->pageQualityScore($b));

        $belowThreshold = array_values(array_filter(
            $frontPages,
            fn (array $page) => $this->quality->pageQualityScore($page) < $minQuality
        ));

        $selected = $belowThreshold !== []
            ? $belowThreshold
            : $frontPages;

        $pageNumbers = array_map(
            fn (array $page) => (int) $page['page_number'],
            array_slice($selected, 0, $maxOcr)
        );

        if ($fallbackRequired) {
            $tocRegionEnd = min(12, $frontLimit);

            for ($pageNumber = 1; $pageNumber <= $tocRegionEnd; $pageNumber++) {
                if (! in_array($pageNumber, $pageNumbers, true)) {
                    $pageNumbers[] = $pageNumber;
                }
            }

            usort($pageNumbers, function (int $a, int $b) use ($pages) {
                $scoreA = $this->pageScoreByNumber($pages, $a);
                $scoreB = $this->pageScoreByNumber($pages, $b);

                return $scoreA <=> $scoreB;
            });

            $pageNumbers = array_slice($pageNumbers, 0, $maxOcr);
        }

        return array_values(array_unique($pageNumbers));
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    private function pageScoreByNumber(array $pages, int $pageNumber): float
    {
        foreach ($pages as $page) {
            if ((int) ($page['page_number'] ?? 0) === $pageNumber) {
                return $this->quality->pageQualityScore($page);
            }
        }

        return 1.0;
    }

    /**
     * @return array{page_number: int, reason: string}|null
     */
    public function ocrSkipReason(array $page, int $frontLimit, bool $fallbackRequired, bool $selected): ?array
    {
        $pageNumber = (int) ($page['page_number'] ?? 0);

        if (! $fallbackRequired) {
            return ['page_number' => $pageNumber, 'reason' => 'front matter trustworthy'];
        }

        if ($pageNumber > $frontLimit) {
            return ['page_number' => $pageNumber, 'reason' => 'outside front matter range'];
        }

        if ($selected) {
            return null;
        }

        $minQuality = (float) config('textbook_extraction.min_page_quality', 0.42);
        $score = $this->quality->pageQualityScore($page);

        return [
            'page_number' => $pageNumber,
            'reason' => 'not in lowest-quality front-matter batch (score '.$score.', threshold '.$minQuality.')',
        ];
    }
}
