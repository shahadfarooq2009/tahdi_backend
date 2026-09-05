<?php

namespace App\Services\Curriculum;

use App\Services\Ai\AiClient;

class StructureDetectionService
{
    public function __construct(
        private readonly TextExtractionService $textExtraction,
        private readonly ArabicStructureDetector $arabicDetector,
        private readonly StructureMergeService $mergeService,
        private readonly StructureCoverageValidator $coverageValidator,
        private readonly TocStructureParserService $tocParser,
        private readonly AiClient $ai,
    ) {}

    /**
     * @param  array<int, array{page_number: int, content_text: string}>  $pages
     * @return array{
     *   structure: array<string, mixed>,
     *   used_ai: bool,
     *   detection_mode: string,
     *   coverage: array<string, mixed>,
     *   merge_actions: array<int, string>
     * }
     */
    public function detectTextbookStructure(array $pages, string $bookTitle): array
    {
        $totalPages = $pages !== []
            ? (int) $pages[array_key_last($pages)]['page_number']
            : 1;

        $normalizedPages = array_map(fn ($page) => [
            'page_number' => $page['page_number'],
            'content_text' => $page['content_text'],
            'normalized_text' => $page['normalized_text'] ?? ArabicTextService::normalizeArabicText($page['content_text']),
            'printed_page_number' => $page['printed_page_number'] ?? null,
        ], $pages);

        $tocParse = $this->tocParser->parse($normalizedPages, $totalPages);
        $tocUnits = $tocParse['units'] ?? [];

        if (count($tocUnits) < 2) {
            $bodyUnits = $this->tocParser->scanBodyUnitHeadings($normalizedPages, $totalPages);

            if (count($bodyUnits) > count($tocUnits)) {
                $tocUnits = $bodyUnits;
            }
        }

        $uniquePdfPages = count(array_unique(array_map(
            fn (array $unit) => (int) ($unit['pdf_page'] ?? 0),
            $tocUnits
        )));

        if ($uniquePdfPages >= 2 && $this->averageConfidence($tocUnits) >= 0.55) {
            $structure = $this->tocParser->buildBookStructure($bookTitle, $tocUnits, $totalPages);

            if (is_array($structure)) {
                $candidatePayload = $this->buildCandidatePayloadFromToc($tocParse);
                $coverage = $this->coverageValidator->validate($structure, $candidatePayload, $totalPages);
                $structure['_meta'] = [
                    'used_ai' => false,
                    'detection_mode' => 'toc_parser',
                    'coverage' => $coverage,
                    'merge_actions' => ['toc_parser_primary'],
                    'review_required' => true,
                    'toc_pdf_pages' => $tocParse['toc_pdf_pages'] ?? [],
                    'unit_confidence' => array_map(
                        fn (array $unit) => [
                            'title' => $unit['title'],
                            'pdf_page' => $unit['pdf_page'],
                            'printed_page' => $unit['printed_page'] ?? null,
                            'confidence' => $unit['confidence'],
                        ],
                        $tocUnits
                    ),
                ];

                return [
                    'structure' => $structure,
                    'used_ai' => false,
                    'detection_mode' => 'toc_parser',
                    'coverage' => $coverage,
                    'merge_actions' => ['toc_parser_primary'],
                ];
            }
        }

        $headings = $this->textExtraction->detectHeadingCandidates($normalizedPages);
        $candidates = $this->arabicDetector->detectCandidates($normalizedPages);

        if ($tocUnits !== [] && count($candidates['units'] ?? []) < 2) {
            $candidates = $this->mergeTocCandidates($candidates, array_values(array_filter(
                $tocUnits,
                fn (array $unit) => (float) ($unit['confidence'] ?? 0) >= 0.6
            )));
        }

        return $this->detectStructureWithAi($bookTitle, $normalizedPages, $headings, $candidates);
    }

    /**
     * @param  array<int, array{page_number: int, content_text: string}>  $pages
     * @param  array<int, array{page_number: int, title: string}>  $headings
     * @param  array{
     *   units: array<int, array{page_number: int, title: string}>,
     *   lessons: array<int, array{page_number: int, title: string}>
     * }|null  $candidates
     * @return array{
     *   structure: array<string, mixed>,
     *   used_ai: bool,
     *   detection_mode: string,
     *   coverage: array<string, mixed>,
     *   merge_actions: array<int, string>
     * }
     */
    public function detectStructureWithAi(
        string $bookTitle,
        array $pages,
        array $headings,
        ?array $candidates = null,
    ): array {
        $totalPages = $pages !== []
            ? (int) $pages[array_key_last($pages)]['page_number']
            : 1;

        $candidatePayload = $candidates ?? $this->arabicDetector->detectCandidates(
            array_map(fn ($page) => [
                'page_number' => $page['page_number'],
                'content_text' => $page['content_text'],
            ], $pages)
        );

        $deterministicStructure = $this->buildDeterministicStructure($bookTitle, $headings, $totalPages, $candidatePayload);
        $mergeActions = ['deterministic_baseline_built'];

        if (! $this->ai->isConfigured()) {
            $coverage = $this->coverageValidator->validate($deterministicStructure, $candidatePayload, $totalPages);

            return $this->packageResult(
                $deterministicStructure,
                usedAi: false,
                detectionMode: 'deterministic',
                coverage: $coverage,
                mergeActions: $mergeActions,
            );
        }

        $aiStructure = $this->requestAiStructure($bookTitle, $pages, $headings, $totalPages);

        if ($aiStructure === null) {
            $coverage = $this->coverageValidator->validate($deterministicStructure, $candidatePayload, $totalPages);
            $mergeActions[] = 'ai_failed_used_deterministic';

            return $this->packageResult(
                $deterministicStructure,
                usedAi: false,
                detectionMode: 'deterministic',
                coverage: $coverage,
                mergeActions: $mergeActions,
            );
        }

        $merged = $this->mergeService->merge(
            $aiStructure,
            $deterministicStructure,
            $candidatePayload,
            $totalPages
        );

        $structure = $merged['structure'];
        $mergeActions = [...$mergeActions, ...$merged['merge_actions']];
        $coverage = $this->coverageValidator->validate($structure, $candidatePayload, $totalPages);

        return $this->packageResult(
            $structure,
            usedAi: true,
            detectionMode: 'hybrid',
            coverage: $coverage,
            mergeActions: $mergeActions,
        );
    }

    /**
     * Decide whether automatic detection produced a trustworthy unit structure.
     *
     * @param  array{
     *   structure: array<string, mixed>,
     *   used_ai: bool,
     *   detection_mode: string,
     *   coverage: array<string, mixed>,
     *   merge_actions: array<int, string>
     * }  $detection
     * @param  array{
     *   units: array<int, array{page_number: int, title: string}>,
     *   lessons: array<int, array{page_number: int, title: string}>
     * }  $candidates
     * @return array{success: bool, reason: string, message: string}
     */
    public function evaluateAutomaticDetection(array $detection, array $candidates, int $totalPages): array
    {
        $structureUnits = $detection['structure']['children'] ?? [];
        $candidateUnits = $candidates['units'] ?? [];
        $unitCount = is_array($structureUnits) ? count($structureUnits) : 0;
        $candidateCount = count($candidateUnits);

        if ($unitCount === 0) {
            return [
                'success' => false,
                'reason' => 'no_units_detected',
                'message' => 'تعذر اكتشاف وحدات الكتاب تلقائياً. أضف الوحدات يدوياً قبل الاعتماد.',
            ];
        }

        $detectionMode = (string) ($detection['detection_mode'] ?? '');

        if ($unitCount >= 2 && in_array($detectionMode, ['toc_parser', 'hybrid', 'deterministic'], true)) {
            $confidenceValues = array_map(
                fn (array $unit) => (float) ($unit['confidence'] ?? 0),
                is_array($structureUnits) ? $structureUnits : []
            );
            $avgConfidence = $confidenceValues === []
                ? 0.0
                : array_sum($confidenceValues) / count($confidenceValues);

            if ($detectionMode === 'toc_parser' || $candidateCount >= 2 || $avgConfidence >= 0.55) {
                return [
                    'success' => true,
                    'reason' => $detectionMode === 'toc_parser' ? 'toc_parser_units' : 'units_detected',
                    'message' => '',
                ];
            }
        }

        if ($unitCount === 1 && $candidateCount === 0) {
            $unit = is_array($structureUnits[0] ?? null) ? $structureUnits[0] : [];
            if ($this->isSyntheticFullBookUnit($unit, $totalPages)) {
                return [
                    'success' => false,
                    'reason' => 'synthetic_fallback_unit',
                    'message' => 'اكتشاف الوحدات فشل وأُنشئت وحدة افتراضية تغطي الكتاب بالكامل. أضف الوحدات يدوياً.',
                ];
            }

            if (! $detection['used_ai']) {
                return [
                    'success' => false,
                    'reason' => 'unverified_single_unit',
                    'message' => 'تعذر التحقق من وحدة واحدة تلقائياً. راجع الهيكل أو أضف الوحدات يدوياً.',
                ];
            }
        }

        return [
            'success' => true,
            'reason' => 'units_detected',
            'message' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildManualStructureShell(string $bookTitle, int $totalPages, array $detectionMeta = []): array
    {
        $structure = $this->arabicDetector->buildEmptyBookStructure($bookTitle, $totalPages);
        $structure['_meta'] = [
            'used_ai' => (bool) ($detectionMeta['used_ai'] ?? false),
            'detection_mode' => (string) ($detectionMeta['detection_mode'] ?? 'failed'),
            'coverage' => $detectionMeta['coverage'] ?? null,
            'merge_actions' => $detectionMeta['merge_actions'] ?? [],
            'review_required' => true,
            'detection_failed' => true,
            'detection_failure_reason' => (string) ($detectionMeta['reason'] ?? 'no_units_detected'),
        ];

        return $structure;
    }

    /**
     * @param  array<string, mixed>  $unit
     */
    private function isSyntheticFullBookUnit(array $unit, int $totalPages): bool
    {
        $startPage = (int) ($unit['start_page'] ?? 0);
        $endPage = (int) ($unit['end_page'] ?? 0);

        if ($startPage !== 1 || $endPage !== $totalPages) {
            return false;
        }

        $normalizedTitle = ArabicTextService::normalizeArabicText((string) ($unit['title'] ?? ''));

        return (bool) preg_match('/^الوحده\s*الاولي$/u', $normalizedTitle);
    }

    /**
     * @param  array<int, array{page_number: int, title: string}>  $headings
     * @param  array{
     *   units?: array<int, array{page_number: int, title: string}>,
     *   lessons?: array<int, array{page_number: int, title: string}>
     * }|null  $candidates
     * @return array<string, mixed>
     */
    public function buildDeterministicStructure(
        string $bookTitle,
        array $headings,
        int $totalPages,
        ?array $candidates = null,
    ): array {
        if ($candidates !== null && ($candidates['units'] ?? []) !== []) {
            return $this->arabicDetector->buildStructure($bookTitle, $candidates, $totalPages);
        }

        $fallbackCandidates = [
            'units' => array_values(array_map(fn (array $heading) => [
                'page_number' => (int) $heading['page_number'],
                'title' => (string) $heading['title'],
                'kind' => preg_match('/الدرس/u', (string) $heading['title']) ? 'lesson' : 'unit',
            ], array_filter(
                $headings,
                fn (array $heading) => (bool) preg_match('/الوحدة|الوحده|الفصل|الدرس/u', (string) $heading['title'])
            ))),
            'lessons' => [],
        ];

        $fallbackCandidates['units'] = array_values(array_filter(
            $fallbackCandidates['units'],
            fn (array $item) => $item['kind'] === 'unit'
        ));
        $fallbackCandidates['lessons'] = array_values(array_filter(
            array_map(fn (array $heading) => [
                'page_number' => (int) $heading['page_number'],
                'title' => (string) $heading['title'],
                'kind' => 'lesson',
            ], $headings),
            fn (array $item) => (bool) preg_match('/الدرس/u', (string) $item['title'])
        ));

        return $this->arabicDetector->buildStructure($bookTitle, $fallbackCandidates, $totalPages);
    }

    /**
     * @param  array<int, array{page_number: int, content_text: string}>  $pages
     * @param  array<int, array{page_number: int, title: string}>  $headings
     * @return array<string, mixed>|null
     */
    private function requestAiStructure(string $bookTitle, array $pages, array $headings, int $totalPages): ?array
    {
        $sample = collect($pages)
            ->take(12)
            ->map(fn ($page) => 'صفحة '.$page['page_number'].': '.mb_substr($page['content_text'], 0, 500))
            ->implode("\n");

        $headingList = collect($headings)
            ->take(40)
            ->map(fn ($heading) => 'صفحة '.$heading['page_number'].': '.$heading['title'])
            ->implode("\n");

        try {
            $content = $this->ai->chat([
                [
                    'role' => 'system',
                    'content' => 'أنت خبير في تحليل هيكل الكتب المدرسية العربية. أعد JSON فقط. لا تحذف أي وحدة أو درس مذكور في العناوين المكتشفة.',
                ],
                [
                    'role' => 'user',
                    'content' => "عنوان الكتاب: {$bookTitle}\nالعناوين المكتشفة (لا تحذف أي منها):\n".($headingList ?: 'لا توجد')."\n\nعينة من الصفحات:\n{$sample}\n\nأعد JSON بالشكل:\n{\n  \"book_title\": \"...\",\n  \"units\": [\n    {\n      \"title\": \"...\",\n      \"start_page\": 1,\n      \"end_page\": 10,\n      \"lessons\": [\n        { \"title\": \"...\", \"start_page\": 1, \"end_page\": 5, \"sections\": [] }\n      ]\n    }\n  ]\n}",
                ],
            ], [
                'model' => $this->ai->generationModel(),
                'temperature' => 0.2,
                'json' => true,
            ]);

            if ($content === '') {
                return null;
            }

            $parsed = $this->parseJsonFromAi($content);

            return [
                'key' => 'book',
                'type' => 'book',
                'title' => $parsed['book_title'] ?? $bookTitle,
                'start_page' => 1,
                'end_page' => $totalPages,
                'children' => array_map(function (array $unit, int $unitIndex) use ($totalPages) {
                    $unitStart = (int) ($unit['start_page'] ?? 1);

                    return [
                        'key' => $this->makeKey('unit', $unitIndex + 1),
                        'type' => 'unit',
                        'title' => $unit['title'] ?? 'الوحدة '.($unitIndex + 1),
                        'heading_page' => $unitStart,
                        'start_page' => $unitStart,
                        'end_page' => (int) ($unit['end_page'] ?? $totalPages),
                        'children' => array_map(function (array $lesson, int $lessonIndex) use ($unitIndex, $totalPages) {
                            $lessonStart = (int) ($lesson['start_page'] ?? 1);

                            return [
                                'key' => $this->makeKey('lesson-'.($unitIndex + 1), $lessonIndex + 1),
                                'type' => 'lesson',
                                'title' => $lesson['title'] ?? 'الدرس '.($lessonIndex + 1),
                                'heading_page' => $lessonStart,
                                'start_page' => $lessonStart,
                                'end_page' => (int) ($lesson['end_page'] ?? $totalPages),
                                'children' => array_map(function (array $section, int $sectionIndex) use ($unitIndex, $lessonIndex, $totalPages) {
                                    $sectionStart = (int) ($section['start_page'] ?? 1);

                                    return [
                                        'key' => $this->makeKey("section-{$unitIndex}-{$lessonIndex}", $sectionIndex + 1),
                                        'type' => 'section',
                                        'title' => $section['title'] ?? 'قسم '.($sectionIndex + 1),
                                        'heading_page' => $sectionStart,
                                        'start_page' => $sectionStart,
                                        'end_page' => (int) ($section['end_page'] ?? $totalPages),
                                        'children' => [],
                                    ];
                                }, $lesson['sections'] ?? []),
                            ];
                        }, $unit['lessons'] ?? []),
                    ];
                }, $parsed['units'] ?? [], array_keys($parsed['units'] ?? [])),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $structure
     * @param  array<string, mixed>  $coverage
     * @param  array<int, string>  $mergeActions
     * @return array{
     *   structure: array<string, mixed>,
     *   used_ai: bool,
     *   detection_mode: string,
     *   coverage: array<string, mixed>,
     *   merge_actions: array<int, string>
     * }
     */
    private function packageResult(
        array $structure,
        bool $usedAi,
        string $detectionMode,
        array $coverage,
        array $mergeActions,
    ): array {
        $structure['_meta'] = [
            'used_ai' => $usedAi,
            'detection_mode' => $detectionMode,
            'coverage' => $coverage,
            'merge_actions' => $mergeActions,
            'review_required' => (bool) ($coverage['review_required'] ?? true),
        ];

        return [
            'structure' => $structure,
            'used_ai' => $usedAi,
            'detection_mode' => $detectionMode,
            'coverage' => $coverage,
            'merge_actions' => $mergeActions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonFromAi(string $content): array
    {
        $trimmed = trim($content);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
    }

    private function makeKey(string $prefix, int $index): string
    {
        return "{$prefix}-{$index}";
    }

    /**
     * @param  list<array{title: string, pdf_page: int, printed_page?: int|null, confidence: float}>  $units
     */
    private function averageConfidence(array $units): float
    {
        if ($units === []) {
            return 0.0;
        }

        $sum = array_sum(array_map(fn (array $unit) => (float) ($unit['confidence'] ?? 0), $units));

        return $sum / count($units);
    }

    /**
     * @param  array{
     *   units: list<array{title: string, pdf_page: int, printed_page?: int|null, confidence: float}>,
     *   lessons: list<array{title: string, pdf_page: int, printed_page?: int|null, confidence: float}>
     * }  $tocParse
     * @return array{units: array<int, array{page_number: int, title: string, kind: string}>, lessons: array<int, array{page_number: int, title: string, kind: string}>}
     */
    private function buildCandidatePayloadFromToc(array $tocParse): array
    {
        return [
            'units' => array_map(fn (array $unit) => [
                'page_number' => (int) $unit['pdf_page'],
                'title' => (string) $unit['title'],
                'kind' => 'unit',
            ], $tocParse['units'] ?? []),
            'lessons' => array_map(fn (array $lesson) => [
                'page_number' => (int) $lesson['pdf_page'],
                'title' => (string) $lesson['title'],
                'kind' => 'lesson',
            ], $tocParse['lessons'] ?? []),
        ];
    }

    /**
     * @param  array{
     *   units: array<int, array{page_number: int, title: string, kind: string}>,
     *   lessons: array<int, array{page_number: int, title: string, kind: string}>
     * }  $candidates
     * @param  list<array{title: string, pdf_page: int, printed_page?: int|null, confidence: float}>  $tocUnits
     * @return array{
     *   units: array<int, array{page_number: int, title: string, kind: string}>,
     *   lessons: array<int, array{page_number: int, title: string, kind: string}>
     * }
     */
    private function mergeTocCandidates(array $candidates, array $tocUnits): array
    {
        $units = $candidates['units'] ?? [];

        foreach ($tocUnits as $tocUnit) {
            $units[] = [
                'page_number' => (int) $tocUnit['pdf_page'],
                'title' => (string) $tocUnit['title'],
                'kind' => 'unit',
            ];
        }

        usort($units, fn (array $a, array $b) => $a['page_number'] <=> $b['page_number']);

        return [
            'units' => $units,
            'lessons' => $candidates['lessons'] ?? [],
        ];
    }
}
