<?php

namespace App\Services\Curriculum;

/**
 * Parses table-of-contents / front-matter text into unit boundaries.
 */
class TocStructureParserService
{
  /** @var list<string> */
    private const TOC_MARKERS = ['الفهرس', 'المحتويات', 'المحتوى', 'فهرس', 'جدول المحتويات'];

    public function __construct(
        private readonly ArabicPdfTextNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, array{
     *   page_number: int,
     *   content_text: string,
     *   printed_page_number?: int|null,
     *   pdf_page?: int
     * }>  $pages
     * @return array{
     *   toc_pdf_pages: list<int>,
     *   units: list<array{
     *     title: string,
     *     pdf_page: int,
     *     printed_page: int|null,
     *     confidence: float,
     *     source: string
     *   }>,
     *   lessons: list<array{title: string, pdf_page: int, printed_page: int|null, confidence: float}>
     * }
     */
    public function parse(array $pages, int $totalPages): array
    {
        $frontLimit = (int) config('textbook_extraction.front_matter_pages', 30);
        $tocPdfPages = [];
        $unitEntries = [];
        $lessonEntries = [];

        foreach ($pages as $page) {
            $pageNumber = (int) $page['page_number'];

            if ($pageNumber > $frontLimit) {
                continue;
            }

            $text = (string) ($page['content_text'] ?? '');
            $normalized = ArabicTextService::normalizeArabicText($text);

            if ($this->isTocPage($normalized, $text)) {
                $tocPdfPages[] = $pageNumber;
            }

            foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
                $line = trim((string) $line);

                if ($line === '') {
                    continue;
                }

                $repaired = $this->normalizer->repairLine($line);
                $entry = $this->parseStructureLine($repaired);

                if ($entry === null) {
                    continue;
                }

                $printedPage = $entry['printed_page'];
                $pdfPage = $this->resolvePdfPage($printedPage, $pageNumber, $pages, $totalPages);

                $payload = [
                    'title' => $entry['title'],
                    'pdf_page' => $pdfPage,
                    'printed_page' => $printedPage,
                    'confidence' => $entry['confidence'],
                    'source' => $tocPdfPages !== [] && in_array($pageNumber, $tocPdfPages, true)
                        ? 'toc_page'
                        : 'front_matter',
                ];

                if ($entry['kind'] === 'unit') {
                    $unitEntries[] = $payload;
                } else {
                    $lessonEntries[] = $payload;
                }
            }
        }

        $unitEntries = $this->deduplicateEntries($unitEntries);
        $lessonEntries = $this->deduplicateEntries($lessonEntries);

        return [
            'toc_pdf_pages' => array_values(array_unique($tocPdfPages)),
            'units' => $unitEntries,
            'lessons' => $lessonEntries,
        ];
    }

    /**
     * Scan the full book for unit heading lines (post-normalization body pages).
     *
     * @param  array<int, array{page_number: int, content_text: string, printed_page_number?: int|null}>  $pages
     * @return list<array{title: string, pdf_page: int, printed_page: int|null, confidence: float, source: string}>
     */
    public function scanBodyUnitHeadings(array $pages, int $totalPages): array
    {
        $units = [];

        foreach ($pages as $page) {
            $pageNumber = (int) $page['page_number'];

            foreach (preg_split('/\R+/u', (string) ($page['content_text'] ?? '')) ?: [] as $line) {
                $line = trim((string) $line);

                if ($line === '') {
                    continue;
                }

                $repaired = $this->normalizer->repairLine($line);
                $entry = $this->parseStructureLine($repaired);

                if ($entry === null || $entry['kind'] !== 'unit') {
                    continue;
                }

                if (($entry['confidence'] ?? 0) < 0.55) {
                    continue;
                }

                $printedPage = $entry['printed_page'] ?? ($page['printed_page_number'] ?? null);
                $pdfPage = $this->resolvePdfPage($printedPage, $pageNumber, $pages, $totalPages);

                $units[] = [
                    'title' => $entry['title'],
                    'pdf_page' => $pdfPage,
                    'printed_page' => $printedPage,
                    'confidence' => $entry['confidence'],
                    'source' => 'body_heading',
                ];
            }
        }

        return $this->deduplicateEntries($units);
    }

    /**
     * @param  list<array{title: string, pdf_page: int, printed_page: int|null, confidence: float, source?: string}>  $units
     * @return array<string, mixed>|null
     */
    public function buildBookStructure(string $bookTitle, array $units, int $totalPages): ?array
    {
        if (count($units) < 1) {
            return null;
        }

        usort($units, fn (array $a, array $b) => $a['pdf_page'] <=> $b['pdf_page']);

        $unitNodes = [];

        foreach ($units as $index => $unit) {
            $startPage = max(1, (int) $unit['pdf_page']);
            $nextStart = isset($units[$index + 1])
                ? max(1, (int) $units[$index + 1]['pdf_page'])
                : ($totalPages + 1);
            $endPage = max($startPage, min($totalPages, $nextStart - 1));

            $unitNodes[] = [
                'key' => 'unit-'.($index + 1),
                'type' => 'unit',
                'title' => (string) $unit['title'],
                'heading_page' => $startPage,
                'start_page' => $startPage,
                'end_page' => $endPage,
                'pdf_page' => $startPage,
                'printed_page' => $unit['printed_page'] ?? null,
                'confidence' => (float) ($unit['confidence'] ?? 0.5),
                'children' => [],
            ];
        }

        return [
            'key' => 'book',
            'type' => 'book',
            'title' => $bookTitle,
            'start_page' => 1,
            'end_page' => $totalPages,
            'children' => $unitNodes,
        ];
    }

    private function isTocPage(string $normalized, string $rawText): bool
    {
        foreach (self::TOC_MARKERS as $marker) {
            if (str_contains($normalized, ArabicTextService::normalizeArabicText($marker))) {
                return true;
            }
        }

        $numberTokens = preg_match_all('/\b\d{1,3}\b/u', $rawText);

        return $numberTokens >= 6 && mb_strlen($normalized) >= 40;
    }

    /**
     * @return array{kind: string, title: string, printed_page: int|null, confidence: float}|null
     */
    private function parseStructureLine(string $line): ?array
    {
        $normalized = ArabicTextService::normalizeArabicText($line);

        if (preg_match(
            '/^(?<title>(?:الوحده|الفصل)\s*(?:الاولي|الثانيه|الثالثه|الرابعه|الخامسه|السادسه|السابعه|الثامنه|التاسعه|العاشره|\d+)(?:\s*[:\-–—]\s*[^\d]{0,80})?)\s+(?<page>\d{1,3})$/u',
            $normalized,
            $match
        )) {
            return [
                'kind' => 'unit',
                'title' => trim($match['title']),
                'printed_page' => (int) $match['page'],
                'confidence' => 0.9,
            ];
        }

        if (preg_match(
            '/^(?<page>\d{1,3})\s+(?<title>(?:الوحده|الفصل)\s*(?:الاولي|الثانيه|الثالثه|الرابعه|الخامسه|\d+).{0,80})$/u',
            $normalized,
            $match
        )) {
            return [
                'kind' => 'unit',
                'title' => trim($match['title']),
                'printed_page' => (int) $match['page'],
                'confidence' => 0.85,
            ];
        }

        if (preg_match(
            '/^(?<title>الدرس\s*(?:الاول|الثاني|الثالث|الرابع|الخامس|\d+)(?:\s*[:\-–—]\s*[^\d]{0,80})?)\s+(?<page>\d{1,3})$/u',
            $normalized,
            $match
        )) {
            return [
                'kind' => 'lesson',
                'title' => trim($match['title']),
                'printed_page' => (int) $match['page'],
                'confidence' => 0.75,
            ];
        }

        if (preg_match('/(?:الوحده|الفصل)\s*(?:الاولي|الثانيه|الثالثه|الرابعه|الخامسه|\d+)/u', $normalized)) {
            $printedPage = null;

            if (preg_match('/\b(\d{1,3})\b/u', $normalized, $pageMatch)) {
                $printedPage = (int) $pageMatch[1];
            }

            return [
                'kind' => 'unit',
                'title' => trim($normalized),
                'printed_page' => $printedPage,
                'confidence' => $printedPage !== null ? 0.65 : 0.45,
            ];
        }

        return null;
    }

    /**
     * @param  array<int, array{page_number: int, printed_page_number?: int|null}>  $pages
     */
    private function resolvePdfPage(?int $printedPage, int $tocPdfPage, array $pages, int $totalPages): int
    {
        if ($printedPage === null || $printedPage < 1) {
            return min($totalPages, $tocPdfPage);
        }

        foreach ($pages as $page) {
            if ((int) ($page['printed_page_number'] ?? 0) === $printedPage) {
                return (int) $page['page_number'];
            }
        }

        $offsets = [];

        foreach ($pages as $page) {
            $printed = $page['printed_page_number'] ?? null;

            if ($printed !== null) {
                $offsets[] = (int) $page['page_number'] - (int) $printed;
            }
        }

        if ($offsets !== []) {
            sort($offsets);
            $medianOffset = $offsets[(int) floor(count($offsets) / 2)];

            return max(1, min($totalPages, $printedPage + $medianOffset));
        }

        return max(1, min($totalPages, $printedPage));
    }

    /**
     * @param  list<array{title: string, pdf_page: int, printed_page: int|null, confidence: float}>  $entries
     * @return list<array{title: string, pdf_page: int, printed_page: int|null, confidence: float}>
     */
    private function deduplicateEntries(array $entries): array
    {
        $seen = [];
        $result = [];

        foreach ($entries as $entry) {
            $pageKey = (int) ($entry['pdf_page'] ?? 0);
            $titleKey = ArabicTextService::normalizeArabicText($entry['title']);
            $key = $pageKey > 0 ? 'p:'.$pageKey : $titleKey.'|'.($entry['printed_page'] ?? 'x');

            if (isset($seen[$key])) {
                $existingIndex = $seen[$key];

                if (($entry['confidence'] ?? 0) > ($result[$existingIndex]['confidence'] ?? 0)) {
                    $result[$existingIndex] = $entry;
                }

                continue;
            }

            $seen[$key] = count($result);
            $result[] = $entry;
        }

        usort($result, fn (array $a, array $b) => $a['pdf_page'] <=> $b['pdf_page']);

        return $result;
    }
}
