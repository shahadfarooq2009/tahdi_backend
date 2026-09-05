<?php

namespace Tests\Unit;

use App\Services\Ai\AiClient;
use App\Services\Curriculum\ArabicStructureDetector;
use App\Services\Curriculum\ChunkingService;
use App\Services\Curriculum\StructureCoverageValidator;
use App\Services\Curriculum\StructureDetectionService;
use App\Services\Curriculum\StructureMergeService;
use Tests\TestCase;

class ArabicStructureDetectionTest extends TestCase
{
    /**
     * @return array<int, array{page_number: int, content_text: string}>
     */
    private function sampleTextbookPages(): array
    {
        return [
            ['page_number' => 1, 'content_text' => 'الوحدة الأولى: مقدمة العلوم'],
            ['page_number' => 2, 'content_text' => 'الدرس الأول: مفهوم الخلية'],
            ['page_number' => 3, 'content_text' => 'الدرس الثاني: أنواع الخلايا'],
            ['page_number' => 4, 'content_text' => 'الوحدة الثانية: الطاقة'],
            ['page_number' => 5, 'content_text' => 'الدرس الأول: مصادر الطاقة'],
            ['page_number' => 6, 'content_text' => 'الدرس الثاني: تحولات الطاقة'],
        ];
    }

    public function test_deterministic_detector_finds_two_units_and_four_lessons(): void
    {
        $detector = app(ArabicStructureDetector::class);
        $pages = $this->sampleTextbookPages();

        $candidates = $detector->detectCandidates($pages);
        $structure = $detector->buildStructure('كتاب العلوم', $candidates, 6);

        $this->assertCount(2, $candidates['units']);
        $this->assertCount(4, $candidates['lessons']);
        $this->assertCount(2, $structure['children']);

        $unitOne = $structure['children'][0];
        $unitTwo = $structure['children'][1];

        $this->assertSame('unit', $unitOne['type']);
        $this->assertSame(1, $unitOne['start_page']);
        $this->assertSame(3, $unitOne['end_page']);
        $this->assertCount(2, $unitOne['children']);

        $this->assertSame('unit', $unitTwo['type']);
        $this->assertSame(4, $unitTwo['start_page']);
        $this->assertSame(6, $unitTwo['end_page']);
        $this->assertCount(2, $unitTwo['children']);

        $this->assertSame(1, $unitOne['children'][0]['start_page']);
        $this->assertSame(2, $unitOne['children'][0]['end_page']);
        $this->assertSame(3, $unitOne['children'][1]['start_page']);
        $this->assertSame(3, $unitOne['children'][1]['end_page']);
    }

    public function test_build_structure_without_candidates_returns_empty_children(): void
    {
        $detector = app(ArabicStructureDetector::class);

        $structure = $detector->buildStructure('كتاب العلوم', ['units' => [], 'lessons' => []], 131);

        $this->assertSame('book', $structure['type']);
        $this->assertSame([], $structure['children']);
        $this->assertSame(131, $structure['end_page']);
    }

    public function test_automatic_detection_fails_for_synthetic_full_book_unit(): void
    {
        $service = app(StructureDetectionService::class);
        $detection = [
            'structure' => [
                'children' => [[
                    'type' => 'unit',
                    'title' => 'الوحدة الأولى',
                    'start_page' => 1,
                    'end_page' => 131,
                ]],
            ],
            'used_ai' => false,
            'detection_mode' => 'deterministic',
            'coverage' => ['complete' => true],
            'merge_actions' => ['deterministic_baseline_built', 'ai_failed_used_deterministic'],
        ];

        $outcome = $service->evaluateAutomaticDetection($detection, ['units' => [], 'lessons' => []], 131);

        $this->assertFalse($outcome['success']);
        $this->assertSame('synthetic_fallback_unit', $outcome['reason']);
    }

    public function test_merge_restores_units_and_lessons_missing_from_ai_output(): void
    {
        $detector = app(ArabicStructureDetector::class);
        $merge = app(StructureMergeService::class);
        $pages = $this->sampleTextbookPages();
        $candidates = $detector->detectCandidates($pages);
        $deterministic = $detector->buildStructure('كتاب العلوم', $candidates, 6);

        $incompleteAi = [
            'key' => 'book',
            'type' => 'book',
            'title' => 'كتاب العلوم',
            'start_page' => 1,
            'end_page' => 6,
            'children' => [[
                'key' => 'unit-1',
                'type' => 'unit',
                'title' => 'الوحدة الثانية: الطاقة',
                'heading_page' => 4,
                'start_page' => 4,
                'end_page' => 6,
                'children' => [[
                    'key' => 'lesson-1-1',
                    'type' => 'lesson',
                    'title' => 'الدرس الثاني: تحولات الطاقة',
                    'heading_page' => 6,
                    'start_page' => 6,
                    'end_page' => 6,
                    'children' => [],
                ]],
            ]],
        ];

        $merged = $merge->merge($incompleteAi, $deterministic, $candidates, 6);

        $this->assertCount(2, $merged['structure']['children']);
        $this->assertContains('restored_missing_unit:unit-1', $merged['merge_actions']);
        $this->assertContains('restored_missing_lesson:lesson-2-1', $merged['merge_actions']);
    }

    public function test_coverage_validation_flags_missing_pages_and_candidates(): void
    {
        $detector = app(ArabicStructureDetector::class);
        $coverage = app(StructureCoverageValidator::class);
        $pages = $this->sampleTextbookPages();
        $candidates = $detector->detectCandidates($pages);

        $incompleteStructure = [
            'key' => 'book',
            'type' => 'book',
            'title' => 'كتاب العلوم',
            'start_page' => 1,
            'end_page' => 6,
            'children' => [[
                'key' => 'unit-1',
                'type' => 'unit',
                'title' => 'الوحدة الثانية: الطاقة',
                'heading_page' => 4,
                'start_page' => 4,
                'end_page' => 6,
                'children' => [[
                    'key' => 'lesson-1-1',
                    'type' => 'lesson',
                    'title' => 'الدرس الثاني: تحولات الطاقة',
                    'heading_page' => 6,
                    'start_page' => 6,
                    'end_page' => 6,
                    'children' => [],
                ]],
            ]],
        ];

        $report = $coverage->validate($incompleteStructure, $candidates, 6);

        $this->assertFalse($report['complete']);
        $this->assertTrue($report['review_required']);
        $this->assertNotEmpty($report['missing_units']);
        $this->assertNotEmpty($report['missing_lessons']);
        $this->assertNotEmpty($report['uncovered_pages']);
        $this->assertLessThan(100, $report['coverage_percent']);
    }

    public function test_complete_structure_covers_all_six_pages(): void
    {
        $detector = app(ArabicStructureDetector::class);
        $coverage = app(StructureCoverageValidator::class);
        $chunking = app(ChunkingService::class);
        $pages = $this->sampleTextbookPages();
        $candidates = $detector->detectCandidates($pages);
        $structure = $detector->buildStructure('كتاب العلوم', $candidates, 6);

        $report = $coverage->validate($structure, $candidates, 6);
        $chunks = $chunking->buildChunksFromStructure($pages, $structure);

        $chunkPages = [];
        foreach ($chunks as $chunk) {
            foreach (range($chunk['source_page_start'], $chunk['source_page_end']) as $page) {
                $chunkPages[$page] = true;
            }
        }

        $this->assertTrue($report['complete']);
        $this->assertSame(100.0, $report['coverage_percent']);
        $this->assertSame([1, 2, 3, 4, 5, 6], $report['covered_pages']);
        $this->assertCount(4, $chunks);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_keys($chunkPages));
    }

    public function test_detect_structure_without_ai_uses_complete_deterministic_structure(): void
    {
        $ai = \Mockery::mock(AiClient::class);
        $ai->shouldReceive('isConfigured')->andReturn(false);

        $service = new StructureDetectionService(
            app(\App\Services\Curriculum\TextExtractionService::class),
            app(ArabicStructureDetector::class),
            app(StructureMergeService::class),
            app(StructureCoverageValidator::class),
            app(\App\Services\Curriculum\TocStructureParserService::class),
            $ai,
        );

        $result = $service->detectTextbookStructure($this->sampleTextbookPages(), 'كتاب العلوم');

        $this->assertSame('deterministic', $result['detection_mode']);
        $this->assertFalse($result['used_ai']);
        $this->assertTrue($result['coverage']['complete']);
        $this->assertCount(2, $result['structure']['children']);
        $this->assertSame(100.0, $result['coverage']['coverage_percent']);
    }
}
