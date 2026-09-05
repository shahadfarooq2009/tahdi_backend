<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Textbook;
use App\Services\Curriculum\TextExtractionService;
use App\Services\Curriculum\TextbookFileStorageService;

$textbookId = $argv[1] ?? Textbook::query()->orderByDesc('created_at')->value('id');

if (! is_string($textbookId) || $textbookId === '') {
    fwrite(STDERR, "No textbook id.\n");
    exit(1);
}

/** @var Textbook|null $textbook */
$textbook = Textbook::query()->find($textbookId);

if ($textbook === null) {
    fwrite(STDERR, "Textbook not found: {$textbookId}\n");
    exit(1);
}

$files = app(TextbookFileStorageService::class);
$extractor = app(TextExtractionService::class);

$absolutePath = $files->absolutePath($textbook);
$started = microtime(true);
$memoryBefore = memory_get_usage(true);

$report = [
    'textbook_id' => $textbook->id,
    'title' => $textbook->title,
    'processing_status' => $textbook->processing_status,
    'last_error' => $textbook->last_error,
    'storage_bucket' => $textbook->storage_bucket,
    'storage_path' => $textbook->storage_path,
    'absolute_path' => $absolutePath,
    'file_exists' => is_file($absolutePath),
    'file_size_bytes' => is_file($absolutePath) ? filesize($absolutePath) : null,
];

try {
    $buffer = $files->read($textbook);
    $report['read_bytes'] = strlen($buffer);
    $report['pdf_magic_ok'] = str_starts_with($buffer, '%PDF');

    $header = substr($buffer, 0, 2048);
    $report['encrypted'] = str_contains($header, '/Encrypt');
    $report['has_text_operators'] = (bool) preg_match('/\/Type\s*\/Page|BT\s|Tj|TJ/', $buffer);

    $pages = $extractor->extractPdfPages($buffer);
    $emptyPages = array_values(array_filter(
        $pages,
        fn (array $page) => trim($page['content_text'] ?? '') === ''
    ));

    $report['page_count'] = count($pages);
    $report['pages_with_text'] = count($pages) - count($emptyPages);
    $report['empty_page_numbers'] = array_map(
        fn (array $page) => $page['page_number'],
        $emptyPages
    );
    $report['sample_page_lengths'] = array_map(
        fn (array $page) => [
            'page' => $page['page_number'],
            'chars' => mb_strlen($page['content_text'] ?? ''),
        ],
        array_slice($pages, 0, 5)
    );
    $report['extraction_ok'] = true;
} catch (Throwable $exception) {
    $report['extraction_ok'] = false;
    $report['exception_class'] = $exception::class;
    $report['exception_message'] = $exception->getMessage();
}

$report['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
$report['memory_peak_mb'] = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
$report['memory_delta_mb'] = round((memory_get_peak_usage(true) - $memoryBefore) / 1024 / 1024, 2);

$jobs = Illuminate\Support\Facades\DB::table('textbook_processing_jobs')
    ->where('textbook_id', $textbook->id)
    ->orderByDesc('created_at')
    ->get(['id', 'job_type', 'status', 'error_message', 'retry_count', 'started_at', 'completed_at']);

$report['jobs'] = $jobs;

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
