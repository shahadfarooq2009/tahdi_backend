<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Textbook;
use App\Services\Curriculum\ChunkingService;
use App\Services\Curriculum\StructureCoverageValidator;
use App\Services\Curriculum\StructureDetectionService;
use App\Services\Curriculum\TextbookJobService;
use App\Services\Curriculum\TextbookService;
use Illuminate\Support\Facades\DB;

$textbookId = $argv[1] ?? null;

$textbook = $textbookId
    ? Textbook::query()->find($textbookId)
    : Textbook::query()->orderByDesc('created_at')->first();

if ($textbook === null) {
    fwrite(STDERR, "No textbook found.\n");
    exit(1);
}

echo "Reprocessing textbook: {$textbook->id} ({$textbook->title})\n";

$pages = DB::table('textbook_pages')
    ->where('textbook_id', $textbook->id)
    ->orderBy('page_number')
    ->get(['page_number', 'content_text'])
    ->map(fn ($row) => [
        'page_number' => (int) $row->page_number,
        'content_text' => (string) $row->content_text,
    ])
    ->all();

if ($pages === []) {
    fwrite(STDERR, "No extracted pages found. Run extract_text first.\n");
    exit(1);
}

$detection = app(StructureDetectionService::class)->detectTextbookStructure($pages, (string) $textbook->title);
$structure = $detection['structure'];
$coverage = $detection['coverage'];

$textbook->update([
    'proposed_structure' => $structure,
    'approved_structure' => null,
    'structure_status' => 'review_required',
    'processing_status' => 'review_required',
    'last_error' => $coverage['complete'] ? null : json_encode([
        'missing_units' => $coverage['missing_units'] ?? [],
        'missing_lessons' => $coverage['missing_lessons'] ?? [],
        'uncovered_pages' => $coverage['uncovered_pages'] ?? [],
    ], JSON_UNESCAPED_UNICODE),
    'updated_at' => now(),
]);

echo 'detection_mode='.$detection['detection_mode'].PHP_EOL;
echo 'used_ai='.($detection['used_ai'] ? 'yes' : 'no').PHP_EOL;
echo 'units_detected='.($coverage['unit_count'] ?? 0).PHP_EOL;
echo 'lessons_detected='.($coverage['lesson_count'] ?? 0).PHP_EOL;
echo 'coverage_percent='.($coverage['coverage_percent'] ?? 0).PHP_EOL;
echo 'covered_pages='.json_encode($coverage['covered_pages'] ?? [], JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'uncovered_pages='.json_encode($coverage['uncovered_pages'] ?? [], JSON_UNESCAPED_UNICODE).PHP_EOL;
echo 'complete='.(($coverage['complete'] ?? false) ? 'yes' : 'no').PHP_EOL;
echo 'merge_actions='.json_encode($detection['merge_actions'] ?? [], JSON_UNESCAPED_UNICODE).PHP_EOL;

echo PHP_EOL.'Detected structure:'.PHP_EOL;
foreach ($structure['children'] ?? [] as $unit) {
    echo '- '.$unit['title'].' (pages '.$unit['start_page'].'-'.$unit['end_page'].')'.PHP_EOL;
    foreach ($unit['children'] ?? [] as $lesson) {
        echo '  * '.$lesson['title'].' (pages '.$lesson['start_page'].'-'.$lesson['end_page'].', heading '.$lesson['heading_page'].')'.PHP_EOL;
    }
}

if ($coverage['complete'] ?? false) {
    $approved = $structure;
    $textbook->update([
        'approved_structure' => $approved,
        'structure_status' => 'approved',
        'processing_status' => 'ready',
        'last_error' => null,
        'updated_at' => now(),
    ]);

    $job = app(TextbookJobService::class)->enqueue($textbook->id, 'build_chunks', [], (string) ($textbook->created_by ?? ''));
    app(TextbookService::class)->runBuildChunks($job);

    $chunkPages = [];
    $chunks = DB::table('textbook_content_chunks')
        ->where('textbook_id', $textbook->id)
        ->orderBy('source_page_start')
        ->get();

    foreach ($chunks as $chunk) {
        foreach (range((int) $chunk->source_page_start, (int) $chunk->source_page_end) as $page) {
            $chunkPages[$page] = true;
        }
    }

    ksort($chunkPages);

    echo PHP_EOL.'chunk_count='.$chunks->count().PHP_EOL;
    echo 'chunk_pages='.json_encode(array_keys($chunkPages), JSON_UNESCAPED_UNICODE).PHP_EOL;
} else {
    echo PHP_EOL.'Structure incomplete — approval and chunk build skipped.'.PHP_EOL;
    exit(2);
}
