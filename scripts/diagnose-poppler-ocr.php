<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Curriculum\PdfExternalTools;
use App\Services\Curriculum\PdfPageOcrService;
use App\Services\Curriculum\PopplerPdfTextExtractor;
use Illuminate\Support\Facades\Process;

$path = realpath(__DIR__.'/../storage/app/private/textbooks/01a04ebe-3f42-7125-a1d8-2662dd1d0dad/original.pdf');
$tools = app(PdfExternalTools::class)->resolve();
$poppler = app(PopplerPdfTextExtractor::class);
$ocr = app(PdfPageOcrService::class);

echo "PDF: {$path}\n";
echo 'pdftotext: '.($tools['pdftotext'] ?? 'null')."\n";
echo 'pdftoppm: '.($tools['pdftoppm'] ?? 'null')."\n";
echo 'tesseract: '.($tools['tesseract'] ?? 'null')."\n";

$raw = Process::timeout(60)->run([
    $tools['pdftotext'],
    '-layout',
    '-enc', 'UTF-8',
    '-f', '1',
    '-l', '3',
    $path,
    '-',
]);

echo "pdftotext exit: {$raw->exitCode()}\n";
echo 'stdout len: '.strlen($raw->output())."\n";
echo 'stderr: '.trim($raw->errorOutput())."\n";
echo 'form feeds: '.substr_count($raw->output(), "\f")."\n";

$range = $poppler->extractPageRange($path, 1, 5);
echo 'extractPageRange count: '.(is_array($range) ? count($range) : 'null')."\n";

if (is_array($range)) {
    foreach ($range as $page) {
        echo "  page {$page['page_number']} len=".strlen($page['content_text'])."\n";
    }
}

for ($page = 1; $page <= 3; $page++) {
    $single = $poppler->extractPage($path, $page);
    echo "extractPage({$page}): ".(is_array($single) ? strlen($single['content_text']) : 'null')."\n";
}

echo 'OCR language: '.$ocr->resolvedLanguage()."\n";
for ($page = 1; $page <= 2; $page++) {
    $result = $ocr->ocrPage($path, $page);
    echo "ocrPage({$page}): ".(is_array($result) ? ('len='.strlen($result['text']).' score='.($result['quality']['score'] ?? '?')) : 'null')."\n";
    if (is_array($result)) {
        echo mb_substr($result['text'], 0, 180)."\n";
    }
}
