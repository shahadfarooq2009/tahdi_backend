<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$rows = DB::table('textbooks')
    ->select('id', 'title', 'processing_status', DB::raw('(select count(*) from textbook_pages where textbook_id = textbooks.id) as page_count'))
    ->orderByDesc('updated_at')
    ->limit(15)
    ->get();

foreach ($rows as $row) {
    echo "{$row->id} | {$row->processing_status} | pages={$row->page_count} | {$row->title}\n";
}
