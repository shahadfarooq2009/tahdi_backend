<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv, true);

$query = DB::table('questions as q')
    ->join('chapters as c', 'q.chapter_id', '=', 'c.id')
    ->join('subjects as s', 'c.subject_id', '=', 's.id')
    ->where('s.challenge_type', 'school')
    ->where('q.is_deleted', false);

$count = (clone $query)->count();

echo "School challenge questions to delete: {$count}\n";

if ($count === 0) {
    exit(0);
}

if ($dryRun) {
    echo "Dry run only. Re-run without --dry-run to soft-delete.\n";
    exit(0);
}

$ids = (clone $query)->pluck('q.id');

$updated = DB::table('questions')
    ->whereIn('id', $ids)
    ->update([
        'is_deleted' => true,
        'updated_at' => now(),
    ]);

echo "Soft-deleted questions: {$updated}\n";

$remaining = DB::table('questions as q')
    ->join('chapters as c', 'q.chapter_id', '=', 'c.id')
    ->join('subjects as s', 'c.subject_id', '=', 's.id')
    ->where('s.challenge_type', 'school')
    ->where('q.is_deleted', false)
    ->count();

echo "Remaining active school questions: {$remaining}\n";
