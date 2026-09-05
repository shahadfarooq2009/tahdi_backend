<?php

namespace Tests\Unit;

use App\Support\ChunkProvenanceResolver;
use PHPUnit\Framework\TestCase;

class ChunkProvenanceResolverTest extends TestCase
{
    private ChunkProvenanceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ChunkProvenanceResolver;
    }

    public function test_single_chunk_preserves_lesson_and_unit_keys(): void
    {
        $result = $this->resolver->resolve([
            [
                'id' => 'chunk-1',
                'unit_key' => 'unit-1',
                'lesson_key' => 'lesson-1-1',
                'source_page_start' => 1,
                'source_page_end' => 2,
            ],
        ], [
            'unit_key' => 'unit-1',
        ]);

        $this->assertSame('unit-1', $result['unit_key']);
        $this->assertSame('lesson-1-1', $result['lesson_key']);
        $this->assertSame(['lesson-1-1'], $result['lesson_keys']);
        $this->assertSame(1, $result['source_page_start']);
        $this->assertSame(2, $result['source_page_end']);
        $this->assertSame(['chunk-1'], $result['source_chunk_ids']);
        $this->assertSame([], $result['provenance_metadata']);
    }

    public function test_multiple_lessons_keep_list_without_fabricated_primary_lesson_key(): void
    {
        $result = $this->resolver->resolve([
            [
                'id' => 'chunk-1',
                'unit_key' => 'unit-1',
                'lesson_key' => 'lesson-1-1',
                'source_page_start' => 1,
                'source_page_end' => 2,
            ],
            [
                'id' => 'chunk-2',
                'unit_key' => 'unit-1',
                'lesson_key' => 'lesson-1-2',
                'source_page_start' => 3,
                'source_page_end' => 3,
            ],
        ]);

        $this->assertNull($result['lesson_key']);
        $this->assertSame(['lesson-1-1', 'lesson-1-2'], $result['lesson_keys']);
        $this->assertSame(['lesson-1-1', 'lesson-1-2'], $result['provenance_metadata']['lesson_keys']);
        $this->assertSame(['chunk-1', 'chunk-2'], $result['provenance_metadata']['source_chunk_ids']);
        $this->assertSame(1, $result['source_page_start']);
        $this->assertSame(3, $result['source_page_end']);
    }

    public function test_payload_unit_key_used_only_when_chunk_units_are_ambiguous(): void
    {
        $result = $this->resolver->resolve([
            [
                'id' => 'chunk-1',
                'unit_key' => 'unit-1',
                'lesson_key' => 'lesson-1-1',
                'source_page_start' => 1,
                'source_page_end' => 2,
            ],
            [
                'id' => 'chunk-2',
                'unit_key' => 'unit-2',
                'lesson_key' => 'lesson-2-1',
                'source_page_start' => 4,
                'source_page_end' => 5,
            ],
        ], [
            'unit_key' => 'unit-1',
        ]);

        $this->assertSame('unit-1', $result['unit_key']);
        $this->assertNull($result['lesson_key']);
        $this->assertSame(['unit-1', 'unit-2'], $result['provenance_metadata']['unit_keys']);
    }

    public function test_no_fabricated_lesson_key_when_chunks_have_no_lesson(): void
    {
        $result = $this->resolver->resolve([
            [
                'id' => 'chunk-1',
                'unit_key' => 'unit-1',
                'lesson_key' => null,
                'source_page_start' => 1,
                'source_page_end' => 2,
            ],
        ], [
            'lesson_key' => 'lesson-1-1',
        ]);

        $this->assertNull($result['lesson_key']);
        $this->assertSame([], $result['lesson_keys']);
    }
}
