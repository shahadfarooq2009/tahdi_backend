<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$subject = DB::table('subjects as s')
    ->join('chapters as c', 'c.subject_id', '=', 's.id')
    ->join('questions as q', 'q.chapter_id', '=', 'c.id')
    ->whereNull('s.category_id')
    ->where('s.is_deleted', false)
    ->whereNull('q.category_id')
    ->where('q.is_deleted', false)
    ->where('q.approval_status', 'approved')
    ->select('s.id', 's.name', DB::raw('count(q.id) as qcount'))
    ->groupBy('s.id', 's.name')
    ->orderByDesc('qcount')
    ->first();

if (! $subject) {
    echo "No school subjects\n";
    exit(1);
}

echo "Subject: {$subject->name} ({$subject->id})\n";

$chapters = DB::table('chapters')->where('subject_id', $subject->id)->limit(5)->get();
foreach ($chapters as $chapter) {
    $count = DB::table('questions')
        ->where('subject_id', $subject->id)
        ->where('chapter_id', $chapter->id)
        ->whereNull('category_id')
        ->where('is_deleted', false)
        ->where('approval_status', 'approved')
        ->count();
    echo "  Chapter: {$chapter->name} => {$count} questions\n";
}

$sample = DB::table('questions')
    ->whereNull('category_id')
    ->where('is_deleted', false)
    ->where('approval_status', 'approved')
    ->select('grade', 'educational_stage', DB::raw('count(*) as c'))
    ->groupBy('grade', 'educational_stage')
    ->orderByDesc('c')
    ->limit(10)
    ->get();

echo "\nGrade/stage distribution:\n";
foreach ($sample as $row) {
    echo "  grade={$row->grade} stage={$row->educational_stage} count={$row->c}\n";
}
