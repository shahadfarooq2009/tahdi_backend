<?php

namespace Tests\Unit;

use App\Support\ChunkProvenanceResolver;
use PHPUnit\Framework\TestCase;

class LessonProvenancePipelineTest extends TestCase
{
    public function test_generation_insert_contract_preserves_chunk_lesson_key(): void
    {
        $resolver = new ChunkProvenanceResolver;
        $sourceChunks = [[
            'id' => '33d19faf-8f59-4c7c-ad97-052eadc88b6c',
            'unit_key' => 'unit-1',
            'lesson_key' => 'lesson-1-1',
            'source_page_start' => 1,
            'source_page_end' => 2,
            'content' => 'محتوى الدرس',
        ]];

        $payload = ['unit_key' => 'unit-1', 'lesson_key' => null];
        $provenance = $resolver->resolve($sourceChunks, $payload);

        $insert = [
            'unit_key' => $provenance['unit_key'],
            'lesson_key' => $provenance['lesson_key'],
            'source_page_start' => $provenance['source_page_start'],
            'source_page_end' => $provenance['source_page_end'],
            'source_chunk_ids' => $resolver->formatPgUuidArray($provenance['source_chunk_ids']),
            'source_grounding' => array_merge(['excerpt' => 'مقتطف'], $provenance['provenance_metadata']),
        ];

        $this->assertSame('unit-1', $insert['unit_key']);
        $this->assertSame('lesson-1-1', $insert['lesson_key']);
        $this->assertSame(1, $insert['source_page_start']);
        $this->assertSame(2, $insert['source_page_end']);
        $this->assertStringContainsString('33d19faf-8f59-4c7c-ad97-052eadc88b6c', $insert['source_chunk_ids']);
        $this->assertArrayNotHasKey('lesson_keys', $insert['source_grounding']);
    }

    public function test_promotion_provenance_reads_lesson_key_from_generated_row(): void
    {
        $generated = (object) [
            'lesson_key' => 'lesson-1-2',
            'unit_key' => 'unit-1',
            'source_page_start' => 3,
            'source_page_end' => 3,
            'source_grounding' => json_encode(['excerpt' => 'مقتطف'], JSON_THROW_ON_ERROR),
        ];

        $sourceGrounding = json_decode((string) $generated->source_grounding, true);
        $provenance = [
            'textbook_id' => 'textbook-id',
            'unit_key' => $generated->unit_key,
            'lesson_key' => $generated->lesson_key,
            'lesson_keys' => is_array($sourceGrounding['lesson_keys'] ?? null)
                ? $sourceGrounding['lesson_keys']
                : array_values(array_filter([(string) ($generated->lesson_key ?? '')])),
            'source_page_start' => $generated->source_page_start,
            'source_page_end' => $generated->source_page_end,
        ];

        $this->assertSame('lesson-1-2', $provenance['lesson_key']);
        $this->assertSame(['lesson-1-2'], $provenance['lesson_keys']);
    }
}
