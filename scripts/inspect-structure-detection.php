<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Curriculum\ArabicStructureDetector;
use App\Services\Curriculum\StructureDetectionService;
use App\Services\Curriculum\TextExtractionService;
use Illuminate\Support\Facades\DB;

$textbookId = $argv[1] ?? null;

$query = DB::table('textbooks')->orderByDesc('updated_at');
if ($textbookId) {
    $query->where('id', $textbookId);
}

$tb = $query->first();
if (! $tb) {
    fwrite(STDERR, "No textbook found.\n");
    exit(1);
}

echo "TEXTBOOK: {$tb->id}\n";
echo "TITLE: {$tb->title}\n";
echo "STATUS: {$tb->processing_status}\n";

$pages = DB::table('textbook_pages')
    ->where('textbook_id', $tb->id)
    ->orderBy('page_number')
    ->get(['page_number', 'content_text']);

$total = $pages->count();
echo "PAGE_COUNT: {$total}\n\n";

$tocKeywords = ['الفهرس', 'المحتويات', 'فهرس', 'جدول المحتويات', 'محتويات الكتاب', 'الموضوع', 'الصفحة'];
$tocPages = [];
foreach ($pages->take(25) as $page) {
    $text = (string) ($page->content_text ?? '');
    foreach ($tocKeywords as $keyword) {
        if (mb_strpos($text, $keyword) !== false) {
            $tocPages[] = (int) $page->page_number;
            break;
        }
    }
}

echo 'TOC_PAGES (keywords in first 25): '.(empty($tocPages) ? 'none' : implode(', ', $tocPages))."\n\n";

$extract = app(TextExtractionService::class);
$detector = app(ArabicStructureDetector::class);

$pageSample = $pages->take(25)->map(fn ($row) => [
    'page_number' => (int) $row->page_number,
    'content_text' => (string) $row->content_text,
])->all();

echo "=== HEADING CANDIDATES (pages 1-25) ===\n";
foreach ($extract->detectHeadingCandidates($pageSample) as $heading) {
    echo "p{$heading['page_number']} [score={$heading['score']}] {$heading['title']}\n";
}

$allPages = $pages->map(fn ($row) => [
    'page_number' => (int) $row->page_number,
    'content_text' => (string) $row->content_text,
])->all();

$candidates = $detector->detectCandidates($allPages);

echo "\n=== UNIT CANDIDATES (all pages) count=".count($candidates['units'])." ===\n";
foreach ($candidates['units'] as $unit) {
    echo "p{$unit['page_number']} | {$unit['title']}\n";
}

echo "\n=== LESSON CANDIDATES (first 20) ===\n";
foreach (array_slice($candidates['lessons'], 0, 20) as $lesson) {
    echo "p{$lesson['page_number']} | {$lesson['title']}\n";
}

$structure = json_decode((string) ($tb->proposed_structure ?? ''), true);
echo "\n=== PROPOSED STRUCTURE UNITS ===\n";
if (is_array($structure)) {
    foreach ($structure['children'] ?? [] as $unit) {
        $title = $unit['title'] ?? '?';
        $start = $unit['start_page'] ?? '?';
        $end = $unit['end_page'] ?? '?';
        echo "{$title} — pages {$start}-{$end}\n";
    }
    echo "\nMETA:\n";
    echo json_encode($structure['_meta'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
}

echo "\n=== PAGE TEXT SAMPLES (pages 1-5, 400 chars) ===\n";
foreach ($pages->take(5) as $page) {
    echo "--- PDF page {$page->page_number} ---\n";
    $text = (string) ($page->content_text ?? '(empty)');
    echo mb_substr($text, 0, 400)."\n\n";
}

echo "=== PRINTED vs PDF PAGE HINTS (first 25) ===\n";
foreach ($pages->take(25) as $page) {
    if (preg_match('/(?:صفحة|ص\s*)(\d+)/u', (string) $page->content_text, $match)) {
        echo "PDF p{$page->page_number} mentions printed page {$match[1]}\n";
    }
}

// Re-run detection pipeline (without persisting) for raw AI comparison
$detection = app(StructureDetectionService::class)->detectTextbookStructure($allPages, (string) $tb->title);
echo "\n=== LIVE DETECTION RESULT ===\n";
echo 'mode='.$detection['detection_mode']."\n";
echo 'used_ai='.($detection['used_ai'] ? 'yes' : 'no')."\n";
echo 'unit_count='.count($detection['structure']['children'] ?? [])."\n";
echo 'merge_actions='.json_encode($detection['merge_actions'], JSON_UNESCAPED_UNICODE)."\n";
echo 'coverage='.json_encode($detection['coverage'], JSON_UNESCAPED_UNICODE)."\n";
