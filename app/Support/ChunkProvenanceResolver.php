<?php

namespace App\Support;

class ChunkProvenanceResolver
{
    /**
     * Resolve textbook provenance from grounding chunk rows.
     *
     * @param  array<int, array<string, mixed>>  $chunks
     * @param  array{unit_key?: ?string, lesson_key?: ?string}  $payloadFallback
     * @return array{
     *     unit_key: ?string,
     *     lesson_key: ?string,
     *     lesson_keys: string[],
     *     source_page_start: ?int,
     *     source_page_end: ?int,
     *     source_chunk_ids: string[],
     *     provenance_metadata: array<string, mixed>
     * }
     */
    public function resolve(array $chunks, array $payloadFallback = []): array
    {
        $sourceChunkIds = array_values(array_filter(array_map(
            fn ($chunk) => isset($chunk['id']) ? (string) $chunk['id'] : null,
            $chunks
        )));

        $unitKeys = $this->uniqueNonEmpty(array_column($chunks, 'unit_key'));
        $lessonKeys = $this->uniqueNonEmpty(array_column($chunks, 'lesson_key'));

        $unitKey = count($unitKeys) === 1
            ? $unitKeys[0]
            : ($payloadFallback['unit_key'] ?? null);

        $lessonKey = count($lessonKeys) === 1
            ? $lessonKeys[0]
            : null;

        $pageStarts = array_values(array_filter(
            array_map(fn ($value) => is_numeric($value) ? (int) $value : null, array_column($chunks, 'source_page_start')),
            fn ($value) => $value !== null
        ));
        $pageEnds = array_values(array_filter(
            array_map(fn ($value) => is_numeric($value) ? (int) $value : null, array_column($chunks, 'source_page_end')),
            fn ($value) => $value !== null
        ));

        $provenanceMetadata = [];

        if (count($lessonKeys) > 1) {
            $provenanceMetadata['lesson_keys'] = $lessonKeys;
        }

        if (count($unitKeys) > 1) {
            $provenanceMetadata['unit_keys'] = $unitKeys;
        }

        if (count($sourceChunkIds) > 1) {
            $provenanceMetadata['source_chunk_ids'] = $sourceChunkIds;
        }

        return [
            'unit_key' => $unitKey,
            'lesson_key' => $lessonKey,
            'lesson_keys' => $lessonKeys,
            'source_page_start' => $pageStarts !== [] ? min($pageStarts) : null,
            'source_page_end' => $pageEnds !== [] ? max($pageEnds) : null,
            'source_chunk_ids' => $sourceChunkIds,
            'provenance_metadata' => $provenanceMetadata,
        ];
    }

    /**
     * @param  string[]  $chunkIds
     */
    public function formatPgUuidArray(array $chunkIds): string
    {
        if ($chunkIds === []) {
            return '{}';
        }

        return '{'.implode(',', array_map(fn (string $id) => '"'.$id.'"', $chunkIds)).'}';
    }

    /**
     * @param  array<int, mixed>  $values
     * @return string[]
     */
    private function uniqueNonEmpty(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($value) => is_string($value) && $value !== '' ? $value : null, $values)
        )));
    }
}
