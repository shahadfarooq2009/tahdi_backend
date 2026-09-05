<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\ChunkProvenanceResolver;
use Illuminate\Support\Facades\DB;

$textbookId = $argv[1] ?? '019fec7a-3c0f-7194-9a66-247cd48de54d';
/** @var ChunkProvenanceResolver $resolver */
$resolver = app(ChunkProvenanceResolver::class);

$rows = DB::table('ai_generated_questions')
    ->where('textbook_id', $textbookId)
    ->orderBy('created_at')
    ->get();

$updated = 0;

foreach ($rows as $row) {
    $chunkIds = array_values(array_filter(array_map(
        'trim',
        explode(',', trim((string) $row->source_chunk_ids, '{}'))
    )));

    if ($chunkIds === []) {
        echo 'SKIP '.$row->id.' (no source chunks)'.PHP_EOL;
        continue;
    }

    $chunks = DB::table('textbook_content_chunks')
        ->whereIn('id', $chunkIds)
        ->get()
        ->map(fn ($chunk) => (array) $chunk)
        ->all();

    $resolved = $resolver->resolve($chunks, [
        'unit_key' => $row->unit_key,
        'lesson_key' => $row->lesson_key,
    ]);

    $sourceGrounding = json_decode((string) ($row->source_grounding ?? '{}'), true);
    if (! is_array($sourceGrounding)) {
        $sourceGrounding = [];
    }

    $nextGrounding = array_merge($sourceGrounding, $resolved['provenance_metadata']);
    $needsUpdate = ($row->lesson_key ?? null) !== $resolved['lesson_key']
        || ($row->unit_key ?? null) !== ($resolved['unit_key'] ?? $row->unit_key)
        || $nextGrounding !== $sourceGrounding;

    if (! $needsUpdate) {
        echo 'OK '.$row->id.' lesson='.$row->lesson_key.PHP_EOL;
        continue;
    }

    DB::table('ai_generated_questions')
        ->where('id', $row->id)
        ->update([
            'unit_key' => $resolved['unit_key'] ?? $row->unit_key,
            'lesson_key' => $resolved['lesson_key'],
            'source_page_start' => $resolved['source_page_start'] ?? $row->source_page_start,
            'source_page_end' => $resolved['source_page_end'] ?? $row->source_page_end,
            'source_grounding' => json_encode($nextGrounding, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

    $updated++;
    echo 'UPDATED '.$row->id.' lesson='.$resolved['lesson_key']
        .' pages='.$resolved['source_page_start'].'-'.$resolved['source_page_end']
        .' chunks='.count($resolved['source_chunk_ids']).PHP_EOL;
}

echo PHP_EOL.'updated='.$updated.' total='.$rows->count().PHP_EOL;

exit(0);
