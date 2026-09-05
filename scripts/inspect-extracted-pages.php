<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Textbook;
use App\Services\Curriculum\ArabicTextService;
use App\Services\Curriculum\LayeredArabicPdfExtractionService;
use App\Services\Curriculum\TextbookFileStorageService;

$id = $argv[1] ?? '01a04ebe-3f42-7125-a1d8-2662dd1d0dad';
$textbook = Textbook::query()->find($id);
$path = app(TextbookFileStorageService::class)->absolutePath($textbook);
$result = app(LayeredArabicPdfExtractionService::class)->extract($path);

foreach ([17, 24, 30, 59] as $pageNumber) {
    $page = $result['pages'][$pageNumber - 1] ?? null;
    echo "=== PDF page {$pageNumber} (quality=".($page['extraction_quality']['score'] ?? '?').") ===\n";
    echo mb_substr((string) ($page['content_text'] ?? ''), 0, 500)."\n\n";
}

$hits = [];
foreach ($result['pages'] as $page) {
    $n = ArabicTextService::normalizeArabicText((string) $page['content_text']);
    foreach (['الوحده', 'الدرس', 'الفهرس', 'المحتويات'] as $token) {
        if (str_contains($n, $token)) {
            $hits[$token][] = (int) $page['page_number'];
        }
    }
}

echo json_encode($hits, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
