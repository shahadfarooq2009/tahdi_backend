<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$id = $argv[1] ?? '01a04ebe-3f42-7125-a1d8-2662dd1d0dad';
$pages = DB::table('textbook_pages')->where('textbook_id', $id)->orderBy('page_number')->get();

$patterns = ['الوحدة', 'الوحده', 'الفصل', 'الدرس', 'المحتويات', 'الفهرس', 'فهرس'];
echo "Searching patterns across {$pages->count()} pages...\n\n";

foreach ($patterns as $pattern) {
    $hits = [];
    foreach ($pages as $page) {
        $text = (string) $page->content_text;
        if ($text !== '' && mb_strpos($text, $pattern) !== false) {
            $hits[] = (int) $page->page_number;
        }
    }
    echo "{$pattern}: ".(empty($hits) ? 'NONE' : implode(', ', array_slice($hits, 0, 30)).(count($hits) > 30 ? ' ... +'.(count($hits)-30) : ''))."\n";
}

echo "\n=== Pages with most text (top 15) ===\n";
$ranked = $pages->map(fn ($p) => ['n' => (int) $p->page_number, 'len' => mb_strlen((string) $p->content_text)])->sortByDesc('len')->take(15);
foreach ($ranked as $row) {
    echo "p{$row['n']}: {$row['len']} chars\n";
}

echo "\n=== Sample high-content pages ===\n";
foreach ($ranked->take(5) as $row) {
    $text = (string) $pages->firstWhere('page_number', $row['n'])->content_text;
    echo "--- p{$row['n']} (first 600 chars) ---\n";
    echo mb_substr($text, 0, 600)."\n\n";
}

echo "\n=== Empty/near-empty pages in first 30 ===\n";
foreach ($pages->take(30) as $page) {
    $len = mb_strlen(trim((string) $page->content_text));
    if ($len < 30) {
        echo "p{$page->page_number}: {$len} chars\n";
    }
}
