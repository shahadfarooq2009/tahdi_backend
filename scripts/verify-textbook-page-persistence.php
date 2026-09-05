<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Textbook;
use App\Services\Curriculum\LayeredArabicPdfExtractionService;
use App\Services\Curriculum\TextbookFileStorageService;
use App\Services\Curriculum\TextbookPagePersistenceMapper;

$textbookId = $argv[1] ?? '01a04ebe-3f42-7125-a1d8-2662dd1d0dad';
$textbook = Textbook::query()->find($textbookId);

if (! $textbook) {
    fwrite(STDERR, "Textbook not found\n");
    exit(1);
}

$path = app(TextbookFileStorageService::class)->absolutePath($textbook);
$extraction = app(LayeredArabicPdfExtractionService::class)->extract($path);
$mapper = app(TextbookPagePersistenceMapper::class);

$rows = $mapper->mapForInsert($extraction['pages'], $textbookId);

$invalid = [];

foreach ($rows as $row) {
    foreach (['content_text', 'normalized_text', 'extraction_source', 'textbook_id'] as $field) {
        if (! is_string($row[$field])) {
            $invalid[] = ['page' => $row['page_number'], 'field' => $field, 'type' => gettype($row[$field])];
        }
    }

    if (! is_int($row['page_number'])) {
        $invalid[] = ['page' => $row['page_number'], 'field' => 'page_number', 'type' => gettype($row['page_number'])];
    }

    if ($row['printed_page_number'] !== null && ! is_int($row['printed_page_number'])) {
        $invalid[] = ['page' => $row['page_number'], 'field' => 'printed_page_number', 'type' => gettype($row['printed_page_number'])];
    }

    if ($row['extraction_quality'] !== null && ! is_float($row['extraction_quality'])) {
        $invalid[] = ['page' => $row['page_number'], 'field' => 'extraction_quality', 'type' => gettype($row['extraction_quality'])];
    }
}

$sample = $extraction['pages'][11] ?? null;

echo json_encode([
    'page_count' => count($rows),
    'invalid_fields' => $invalid,
    'sample_page_12_raw_extraction_quality' => $sample['extraction_quality'] ?? null,
    'sample_page_12_mapped_extraction_quality' => $rows[11]['extraction_quality'] ?? null,
    'sample_page_12_extraction_source' => $rows[11]['extraction_source'] ?? null,
    'all_pages_mappable' => $invalid === [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

exit($invalid === [] ? 0 : 1);
