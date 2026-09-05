<?php

namespace App\Services\Curriculum;

use App\Support\Utf8Text;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

/**
 * Layered Arabic PDF extraction:
 * A) native text + RTL normalization + noise stripping
 * B) per-page quality scoring
 * C) Poppler + OCR fallback for front matter when quality is poor
 */
class LayeredArabicPdfExtractionService
{
    public function __construct(
        private readonly ArabicPdfTextNormalizer $normalizer,
        private readonly ArabicExtractionQualityService $quality,
        private readonly PdfPageOcrService $ocr,
        private readonly PopplerPdfTextExtractor $poppler,
        private readonly PdfExternalTools $tools,
        private readonly TextbookExtractionFallbackSelector $fallbackSelector,
    ) {}

    /**
     * @param  callable(int $pageNumber, int $totalPages): void|null  $onPageExtracted
     * @param  callable(int $ocrDone, int $ocrTotal): void|null  $onOcrProgress
     * @return array{
     *   pages: array<int, array<string, mixed>>,
     *   diagnostics: array<string, mixed>
     * }
     */
    public function extract(string $absolutePdfPath, ?callable $onPageExtracted = null, ?callable $onOcrProgress = null): array
    {
        $startedAt = microtime(true);
        $metrics = [
            'native_parse_ms' => 0,
            'poppler_ms' => 0,
            'ocr_ms' => 0,
            'quality_ms' => 0,
        ];

        $nativeStartedAt = microtime(true);
        $nativeLoad = $this->extractNativePagesWithProgress($absolutePdfPath, $onPageExtracted);
        $rawPages = $nativeLoad['pages'];
        $nativeSource = $nativeLoad['source'];
        $metrics['native_parse_ms'] = (int) round((microtime(true) - $nativeStartedAt) * 1000);

        $totalPages = count($rawPages);
        $frontLimit = (int) config('textbook_extraction.front_matter_pages', 30);
        $sampleSize = (int) config('textbook_extraction.front_matter_quality_sample_pages', 20);
        $maxOcr = (int) config('textbook_extraction.max_ocr_pages', 25);
        $pageDiagnostics = [];

        /** @var array<int, array<string, mixed>> $pages */
        $pages = [];

        foreach ($rawPages as $rawPage) {
            $pageNumber = (int) $rawPage['page_number'];
            $nativeRaw = (string) $rawPage['content_text'];

            $pages[] = $this->buildPageRecord(
                pageNumber: $pageNumber,
                text: $nativeRaw,
                nativeRaw: $nativeRaw,
                source: $nativeSource === 'poppler' ? 'poppler' : 'native',
            );
        }

        $qualityStartedAt = microtime(true);
        $frontMatterAverage = $this->quality->frontMatterAverageQuality($pages, $sampleSize);
        $trustFrontMatter = $frontMatterAverage >= (float) config('textbook_extraction.front_matter_quality_threshold', 0.45);
        $fallbackRequired = ! $trustFrontMatter;
        $metrics['quality_ms'] += (int) round((microtime(true) - $qualityStartedAt) * 1000);

        logger()->info('Front matter quality assessment', [
            'average_quality' => $frontMatterAverage,
            'trustworthy' => $trustFrontMatter,
            'fallback_required' => $fallbackRequired,
            'page_count' => $totalPages,
            'native_source' => $nativeSource,
        ]);

        if ($nativeSource === 'poppler') {
            $popplerByPage = [];
            foreach ($rawPages as $rawPage) {
                $pageNumber = (int) $rawPage['page_number'];
                if ($pageNumber <= $frontLimit) {
                    $popplerByPage[$pageNumber] = (string) $rawPage['content_text'];
                }
            }

            $popplerLoad = [
                'pages' => $popplerByPage,
                'processed_pages' => array_keys($popplerByPage),
                'errors' => [],
            ];
        } else {
            $popplerStartedAt = microtime(true);
            $popplerLoad = $this->loadPopplerFrontMatter($absolutePdfPath, $frontLimit);
            $metrics['poppler_ms'] = (int) round((microtime(true) - $popplerStartedAt) * 1000);
        }

        $popplerByPage = $popplerLoad['pages'];
        $popplerProcessedPages = $popplerLoad['processed_pages'];
        $popplerErrors = $popplerLoad['errors'];
        $popplerUsedPages = [];
        $ocrPages = [];
        $ocrAttemptedPages = [];

        if ($fallbackRequired && $popplerByPage !== [] && $nativeSource !== 'poppler') {
            foreach ($pages as $index => $page) {
                $pageNumber = (int) $page['page_number'];

                if ($pageNumber > $frontLimit || ! isset($popplerByPage[$pageNumber])) {
                    continue;
                }

                $nativeRaw = (string) ($page['raw_text'] ?? '');
                $nativeScore = $this->quality->pageQualityScore($page);
                $popplerRaw = $popplerByPage[$pageNumber];
                $popplerNormalized = $this->normalizer->normalizePageText($popplerRaw);
                $popplerScore = $this->quality->assessPage($popplerNormalized)['score'];

                $pageDiagnostics[$pageNumber]['native_quality'] = $nativeScore;
                $pageDiagnostics[$pageNumber]['poppler_quality'] = $popplerScore;

                if ($popplerScore >= $nativeScore) {
                    $pages[$index] = $this->buildPageRecord(
                        pageNumber: $pageNumber,
                        text: $popplerRaw,
                        nativeRaw: $nativeRaw,
                        source: 'poppler',
                    );
                    $popplerUsedPages[] = $pageNumber;
                }
            }
        }

        $ocrCandidates = [];

        if ($fallbackRequired && $this->ocr->isAvailable()) {
            $ocrCandidates = $this->fallbackSelector->selectOcrPages($pages, $frontLimit, $maxOcr, true);

            logger()->info('OCR candidate pages selected', [
                'count' => count($ocrCandidates),
                'pages' => $ocrCandidates,
            ]);

            $ocrStartedAt = microtime(true);
            $ocrSessionDir = storage_path('app/tmp/ocr/'.Str::uuid());
            $ocrBatchSize = max(1, (int) config('textbook_extraction.ocr_parallel_pages', 2));
            $ocrDone = 0;

            try {
                foreach (array_chunk($ocrCandidates, $ocrBatchSize) as $batch) {
                    $batchResults = $this->ocr->ocrPagesBatch($absolutePdfPath, $batch, $ocrSessionDir);

                    foreach ($batch as $pageNumber) {
                        $ocrDone++;

                        if ($onOcrProgress !== null) {
                            $onOcrProgress($ocrDone, count($ocrCandidates));
                        }

                        $existingIndex = $pageNumber - 1;

                        if (! isset($pages[$existingIndex])) {
                            continue;
                        }

                        $beforeScore = $this->quality->pageQualityScore($pages[$existingIndex]);
                        $pageDiagnostics[$pageNumber]['pre_ocr_quality'] = $beforeScore;
                        $ocrAttemptedPages[] = $pageNumber;

                        $ocrResult = $batchResults[$pageNumber] ?? null;

                        if ($ocrResult === null) {
                            $pageDiagnostics[$pageNumber]['ocr_selected'] = false;
                            $pageDiagnostics[$pageNumber]['ocr_skip_reason'] = 'OCR returned no text';

                            continue;
                        }

                        $ocrScore = (float) ($ocrResult['quality']['score'] ?? 0);
                        $pageDiagnostics[$pageNumber]['ocr_quality'] = $ocrScore;

                        if ($ocrScore <= $beforeScore) {
                            $pageDiagnostics[$pageNumber]['ocr_selected'] = false;
                            $pageDiagnostics[$pageNumber]['ocr_skip_reason'] = 'OCR quality not better than existing';

                            continue;
                        }

                        $nativeRaw = (string) ($pages[$existingIndex]['raw_text'] ?? '');
                        $pages[$existingIndex] = $this->buildPageRecord(
                            pageNumber: $pageNumber,
                            text: $ocrResult['text'],
                            nativeRaw: $nativeRaw,
                            source: 'ocr',
                        );
                        $ocrPages[] = $pageNumber;
                        $pageDiagnostics[$pageNumber]['ocr_selected'] = true;
                    }
                }
            } finally {
                $this->ocr->cleanupDirectory($ocrSessionDir);
            }

            $metrics['ocr_ms'] = (int) round((microtime(true) - $ocrStartedAt) * 1000);
        }

        foreach (array_slice($pages, 0, min($frontLimit, 10)) as $page) {
            $pageNumber = (int) $page['page_number'];
            $pageDiagnostics[$pageNumber] = array_merge(
                $pageDiagnostics[$pageNumber] ?? [],
                [
                    'native_quality' => $pageDiagnostics[$pageNumber]['native_quality']
                        ?? $this->quality->pageQualityScore($page),
                    'final_source' => $page['extraction_source'] ?? 'native',
                    'final_quality' => $this->quality->pageQualityScore($page),
                ]
            );
        }

        $metrics['total_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        $diagnostics = [
            'library' => $nativeSource === 'poppler' ? 'poppler/pdftotext' : 'smalot/pdfparser',
            'native_source' => $nativeSource,
            'poppler_available' => $this->poppler->isAvailable(),
            'ocr_available' => $this->ocr->isAvailable(),
            'ocr_language' => $this->ocr->resolvedLanguage(),
            'tools' => $this->tools->resolve(),
            'front_matter_pages' => $frontLimit,
            'front_matter_average_quality' => round($frontMatterAverage, 4),
            'front_matter_trustworthy' => $trustFrontMatter,
            'fallback_required' => $fallbackRequired,
            'poppler_processed_pages' => $popplerProcessedPages,
            'poppler_used_pages' => array_values(array_unique($popplerUsedPages)),
            'poppler_errors' => $popplerErrors,
            'ocr_candidate_pages' => $ocrCandidates,
            'ocr_attempted_pages' => $ocrAttemptedPages,
            'ocr_pages' => array_values(array_unique($ocrPages)),
            'ocr_triggered' => $fallbackRequired && $this->ocr->isAvailable() && $ocrCandidates !== [],
            'ocr_scope' => 'front_matter_only',
            'page_diagnostics_sample' => array_slice($pageDiagnostics, 0, 15, true),
            'metrics' => $metrics,
            'elapsed_ms' => $metrics['total_ms'],
            'page_count' => $totalPages,
        ];

        return [
            'pages' => $pages,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array{pages: array<int, string>, processed_pages: list<int>, errors: array<string, mixed>}
     */
    private function loadPopplerFrontMatter(string $absolutePdfPath, int $frontLimit): array
    {
        if (! $this->poppler->isAvailable()) {
            return ['pages' => [], 'processed_pages' => [], 'errors' => ['reason' => 'pdftotext unavailable']];
        }

        $result = $this->poppler->extractPageRangeWithDiagnostics($absolutePdfPath, 1, $frontLimit);
        $popplerByPage = [];

        foreach ($result['pages'] as $popplerPage) {
            $popplerByPage[(int) $popplerPage['page_number']] = (string) $popplerPage['content_text'];
        }

        $processedPages = array_keys($popplerByPage);

        if ($popplerByPage === [] && $result['errors'] !== []) {
            logger()->warning('Poppler front-matter extraction failed', $result['errors']);
        }

        return [
            'pages' => $popplerByPage,
            'processed_pages' => $processedPages,
            'errors' => $result['errors'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPageRecord(int $pageNumber, string $text, string $nativeRaw, string $source): array
    {
        $contentText = $this->normalizer->normalizePageText($text);
        $quality = $this->quality->assessPage($contentText, $text);

        return [
            'page_number' => $pageNumber,
            'pdf_page' => $pageNumber,
            'content_text' => Utf8Text::sanitize($contentText),
            'normalized_text' => ArabicTextService::normalizeArabicText($contentText),
            'printed_page_number' => $this->normalizer->detectPrintedPageNumber($contentText),
            'extraction_source' => $source,
            'extraction_quality' => $quality,
            'raw_text' => Utf8Text::sanitize($nativeRaw),
        ];
    }

    /**
     * Extract native text in batches so progress can be persisted during long PDFs.
     *
     * @param  callable(int $processedPages, int $totalPages): void|null  $onProgress
     * @return array{pages: array<int, array{page_number: int, content_text: string}>, source: string}
     */
    private function extractNativePagesWithProgress(string $absolutePdfPath, ?callable $onProgress): array
    {
        $batchSize = max(1, (int) config('textbook_extraction.native_extract_batch_pages', 10));
        $fileSizeBytes = is_file($absolutePdfPath) ? (int) filesize($absolutePdfPath) : 0;
        $smalotMaxBytes = (int) config('textbook_extraction.smalot_max_file_bytes', 50 * 1024 * 1024);
        $totalPages = $this->tools->pdfPageCount($absolutePdfPath);

        if ($this->poppler->isAvailable()) {
            if ($totalPages !== null && $totalPages > 0) {
                return $this->extractNativePagesWithPopplerBatches($absolutePdfPath, $totalPages, $batchSize, $onProgress);
            }

            $discovered = $this->extractNativePagesWithPopplerDiscovery($absolutePdfPath, $batchSize, $onProgress);
            if ($discovered['pages'] !== []) {
                return $discovered;
            }
        }

        if ($fileSizeBytes > $smalotMaxBytes) {
            throw new \RuntimeException(
                'ملف PDF كبير جداً لاستخراجه بدون Poppler. ثبّت Poppler (pdftotext + pdfinfo) أو عيّن PDFTOTEXT_PATH في .env.'
            );
        }

        $parser = new Parser;
        $pdf = $parser->parseFile($absolutePdfPath);
        $pdfPages = $pdf->getPages();
        $totalPages = count($pdfPages);
        $pages = [];

        if ($onProgress !== null) {
            $onProgress(0, $totalPages);
        }

        foreach ($pdfPages as $index => $page) {
            $pageNumber = $index + 1;
            $pages[] = [
                'page_number' => $pageNumber,
                'content_text' => trim($page->getText()),
            ];

            if ($onProgress !== null && ($pageNumber % $batchSize === 0 || $pageNumber === $totalPages)) {
                $onProgress($pageNumber, $totalPages);
            }
        }

        return ['pages' => $pages, 'source' => 'smalot'];
    }

    /**
     * @param  callable(int $processedPages, int $totalPages): void|null  $onProgress
     * @return array{pages: array<int, array{page_number: int, content_text: string}>, source: string}
     */
    private function extractNativePagesWithPopplerBatches(
        string $absolutePdfPath,
        int $totalPages,
        int $batchSize,
        ?callable $onProgress,
    ): array {
        $pages = [];

        if ($onProgress !== null) {
            $onProgress(0, $totalPages);
        }

        for ($start = 1; $start <= $totalPages; $start += $batchSize) {
            $end = min($totalPages, $start + $batchSize - 1);
            $batch = $this->poppler->extractPageRangeWithDiagnostics($absolutePdfPath, $start, $end);

            foreach ($batch['pages'] as $page) {
                $pages[] = [
                    'page_number' => (int) $page['page_number'],
                    'content_text' => (string) $page['content_text'],
                ];
            }

            if ($onProgress !== null) {
                $onProgress($end, $totalPages);
            }
        }

        return ['pages' => $pages, 'source' => 'poppler'];
    }

    /**
     * @param  callable(int $processedPages, int $totalPages): void|null  $onProgress
     * @return array{pages: array<int, array{page_number: int, content_text: string}>, source: string}
     */
    private function extractNativePagesWithPopplerDiscovery(
        string $absolutePdfPath,
        int $batchSize,
        ?callable $onProgress,
    ): array {
        $pages = [];
        $start = 1;

        if ($onProgress !== null) {
            $onProgress(0, 0);
        }

        while (true) {
            $end = $start + $batchSize - 1;
            $batch = $this->poppler->extractPageRangeWithDiagnostics($absolutePdfPath, $start, $end);

            if ($batch['pages'] === []) {
                break;
            }

            foreach ($batch['pages'] as $page) {
                $pages[] = [
                    'page_number' => (int) $page['page_number'],
                    'content_text' => (string) $page['content_text'],
                ];
            }

            $lastPage = (int) end($batch['pages'])['page_number'];

            if ($onProgress !== null) {
                $onProgress($lastPage, max($lastPage, count($pages)));
            }

            if (count($batch['pages']) < $batchSize) {
                break;
            }

            $start += $batchSize;
        }

        return ['pages' => $pages, 'source' => 'poppler'];
    }
}
