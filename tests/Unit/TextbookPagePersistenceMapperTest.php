<?php

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Services\Curriculum\TextbookPagePersistenceMapper;
use Tests\TestCase;

class TextbookPagePersistenceMapperTest extends TestCase
{
    public function test_maps_layered_extraction_quality_array_to_scalar_score(): void
    {
        $mapper = app(TextbookPagePersistenceMapper::class);

        $row = $mapper->mapSinglePage([
            'page_number' => 12,
            'content_text' => 'الوحدة الأولى',
            'normalized_text' => 'الوحده الاولي',
            'printed_page_number' => 10,
            'extraction_source' => 'native',
            'extraction_quality' => [
                'score' => 0.5123,
                'arabic_ratio' => 0.8,
                'token_hits' => 2,
                'word_continuity' => 0.7,
                'acceptable' => true,
            ],
        ], '01a04ebe-3f42-7125-a1d8-2662dd1d0dad');

        $this->assertSame(12, $row['page_number']);
        $this->assertSame('native', $row['extraction_source']);
        $this->assertSame(10, $row['printed_page_number']);
        $this->assertIsFloat($row['extraction_quality']);
        $this->assertSame(0.5123, $row['extraction_quality']);
        $this->assertIsString($row['content_text']);
        $this->assertIsString($row['normalized_text']);
        $this->assertIsString($row['textbook_id']);
    }

    public function test_rejects_array_extraction_quality_without_score_key(): void
    {
        $mapper = app(TextbookPagePersistenceMapper::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Page 3 extraction_quality expected float, array given without score key');

        $mapper->mapSinglePage([
            'page_number' => 3,
            'content_text' => 'test',
            'normalized_text' => 'test',
            'extraction_source' => 'ocr',
            'extraction_quality' => ['arabic_ratio' => 0.5],
        ], '01a04ebe-3f42-7125-a1d8-2662dd1d0dad');
    }

    public function test_accepts_scalar_extraction_quality(): void
    {
        $mapper = app(TextbookPagePersistenceMapper::class);

        $row = $mapper->mapSinglePage([
            'page_number' => 1,
            'content_text' => 'abc',
            'normalized_text' => 'abc',
            'extraction_source' => 'poppler',
            'extraction_quality' => 0.91,
        ], '01a04ebe-3f42-7125-a1d8-2662dd1d0dad');

        $this->assertSame(0.91, $row['extraction_quality']);
    }
}
