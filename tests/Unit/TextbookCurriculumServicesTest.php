<?php

namespace Tests\Unit;

use App\Services\Curriculum\StructureDetectionService;
use App\Services\Curriculum\StructurePatchService;
use App\Services\Curriculum\TextExtractionService;
use Tests\TestCase;

class TextbookCurriculumServicesTest extends TestCase
{
    public function test_pdf_magic_validation_rejects_invalid_buffer(): void
    {
        $service = new TextExtractionService;

        $this->expectException(\App\Exceptions\ValidationException::class);
        $service->assertPdfBuffer('not-a-pdf');
    }

    public function test_pdf_magic_validation_accepts_pdf_header(): void
    {
        $service = new TextExtractionService;

        $service->assertPdfBuffer('%PDF-1.4 sample');
        $this->assertTrue(true);
    }

    public function test_structure_patch_renames_node(): void
    {
        $structure = [
            'key' => 'book',
            'type' => 'book',
            'title' => 'Book',
            'start_page' => 1,
            'end_page' => 10,
            'children' => [[
                'key' => 'unit-1',
                'type' => 'unit',
                'title' => 'Old Unit',
                'start_page' => 1,
                'end_page' => 10,
                'children' => [],
            ]],
        ];

        $patched = (new StructurePatchService)->apply($structure, [[
            'action' => 'rename',
            'key' => 'unit-1',
            'title' => 'New Unit',
        ]]);

        $this->assertSame('New Unit', $patched['children'][0]['title']);
    }

    public function test_deterministic_structure_builds_units_and_lessons(): void
    {
        $service = app(StructureDetectionService::class);

        $structure = $service->buildDeterministicStructure('كتاب الرياضيات', [
            ['page_number' => 1, 'title' => 'الوحدة الأولى'],
            ['page_number' => 3, 'title' => 'الدرس 1'],
            ['page_number' => 8, 'title' => 'الوحدة الثانية'],
        ], 12);

        $this->assertSame('book', $structure['key']);
        $this->assertCount(2, $structure['children']);
        $this->assertSame('unit', $structure['children'][0]['type']);
        $this->assertNotEmpty($structure['children'][0]['children']);
    }
}
