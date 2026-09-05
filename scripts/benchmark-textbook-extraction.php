<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Textbook;
use App\Services\Curriculum\ArabicStructureDetector;
use App\Services\Curriculum\ArabicTextService;
use App\Services\Curriculum\LayeredArabicPdfExtractionService;
use App\Services\Curriculum\StructureDetectionService;
use App\Services\Curriculum\TextbookFileStorageService;

$textbookId = $argv[1] ?? '01a04ebe-3f42-7125-a1d8-2662dd1d0dad';

/** @var Textbook|null $textbook */
$textbook = Textbook::query()->find($textbookId);

if ($textbook === null) {
    fwrite(STDERR, "Textbook not found: {$textbookId}\n");
    exit(1);
}

$files = app(TextbookFileStorageService::class);
$path = $files->absolutePath($textbook);

$layeredStarted = microtime(true);
$layered = app(LayeredArabicPdfExtractionService::class)->extract($path);
$layeredElapsed = (int) round((microtime(true) - $layeredStarted) * 1000);

$legacyPage1 = (string) ($layered['pages'][0]['raw_text'] ?? '');
$layeredPage1 = (string) ($layered['pages'][0]['content_text'] ?? '');
$legacyElapsed = $layeredElapsed;

$detectionStarted = microtime(true);
$detection = app(StructureDetectionService::class)->detectTextbookStructure($layered['pages'], (string) $textbook->title);
$detectionElapsed = (int) round((microtime(true) - $detectionStarted) * 1000);

$detector = app(ArabicStructureDetector::class);
$candidates = $detector->detectCandidates($layered['pages']);

$tokens = ['الوحده', 'الدرس', 'الفصل', 'الفهرس', 'المحتويات', 'الكتاب'];

$tokenHits = [];
foreach ($tokens as $token) {
    $hits = 0;
    foreach (array_slice($layered['pages'], 0, 30) as $page) {
        if (str_contains(
            ArabicTextService::normalizeArabicText((string) $page['content_text']),
            $token
        )) {
            $hits++;
        }
    }
    $tokenHits[$token] = $hits;
}

$units = $detection['structure']['children'] ?? [];

$ocrSamplePages = array_slice($layered['diagnostics']['ocr_pages'] ?? [], 0, 3);
$ocrSamples = [];

foreach ($ocrSamplePages as $pageNumber) {
    $page = $layered['pages'][$pageNumber - 1] ?? null;

    if ($page !== null) {
        $ocrSamples[$pageNumber] = mb_substr((string) ($page['content_text'] ?? ''), 0, 280);
    }
}

echo json_encode([
    'textbook_id' => $textbook->id,
    'title' => $textbook->title,
    'pdf_library' => 'smalot/pdfparser (+ layered pipeline)',
    'legacy_extraction_ms' => $legacyElapsed,
    'layered_extraction_ms' => $layeredElapsed,
    'structure_detection_ms' => $detectionElapsed,
    'total_processing_ms' => $layeredElapsed + $detectionElapsed,
    'ocr_available' => $layered['diagnostics']['ocr_available'] ?? false,
    'poppler_available' => $layered['diagnostics']['poppler_available'] ?? false,
    'ocr_language' => $layered['diagnostics']['ocr_language'] ?? null,
    'fallback_required' => $layered['diagnostics']['fallback_required'] ?? null,
    'front_matter_average_quality' => $layered['diagnostics']['front_matter_average_quality'] ?? null,
    'ocr_pages' => $layered['diagnostics']['ocr_pages'] ?? [],
    'ocr_candidate_pages' => $layered['diagnostics']['ocr_candidate_pages'] ?? [],
    'ocr_attempted_pages' => $layered['diagnostics']['ocr_attempted_pages'] ?? [],
    'poppler_processed_pages' => $layered['diagnostics']['poppler_processed_pages'] ?? [],
    'poppler_used_pages' => $layered['diagnostics']['poppler_used_pages'] ?? [],
    'poppler_pages_processed' => count($layered['diagnostics']['poppler_processed_pages'] ?? []),
    'poppler_errors' => $layered['diagnostics']['poppler_errors'] ?? [],
    'ocr_pages_processed' => count($layered['diagnostics']['ocr_attempted_pages'] ?? []),
    'front_matter_trustworthy' => $layered['diagnostics']['front_matter_trustworthy'] ?? null,
    'page_diagnostics_sample' => $layered['diagnostics']['page_diagnostics_sample'] ?? [],
    'ocr_text_samples' => $ocrSamples,
    'sample_page_1_before' => mb_substr($legacyPage1, 0, 220),
    'sample_page_1_after' => mb_substr($layeredPage1, 0, 220),
    'token_hits_first_30_pages' => $tokenHits,
    'detected_unit_candidates' => count($candidates['units'] ?? []),
    'detection_mode' => $detection['detection_mode'] ?? null,
    'toc_pdf_pages' => $detection['structure']['_meta']['toc_pdf_pages'] ?? [],
    'detected_units' => array_map(fn ($unit) => [
        'title' => $unit['title'] ?? null,
        'pdf_page' => $unit['pdf_page'] ?? $unit['start_page'] ?? null,
        'printed_page' => $unit['printed_page'] ?? null,
        'start_page' => $unit['start_page'] ?? null,
        'end_page' => $unit['end_page'] ?? null,
        'confidence' => $unit['confidence'] ?? null,
    ], is_array($units) ? $units : []),
    'unit_confidence_meta' => $detection['structure']['_meta']['unit_confidence'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
