<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$dryRun = in_array('--dry-run', $argv, true);

$subjectIds = DB::table('subjects')
    ->where('challenge_type', 'school')
    ->where('is_deleted', false)
    ->pluck('id');

$count = $subjectIds->count();

echo "Active school subjects to delete: {$count}\n";

if ($count === 0) {
    exit(0);
}

$sample = DB::table('subjects')
    ->whereIn('id', $subjectIds->take(3))
    ->get(['id', 'name', 'icon_url']);

echo "Sample subjects (image URLs kept on disk):\n";
foreach ($sample as $row) {
    $icon = $row->icon_url ?? '(none)';
    echo " - {$row->name}: {$icon}\n";
}

$chapterCount = DB::table('chapters')
    ->whereIn('subject_id', $subjectIds)
    ->where('is_deleted', false)
    ->count();

echo "Related chapters to soft-delete: {$chapterCount}\n";

if ($dryRun) {
    echo "Dry run only. Re-run without --dry-run to soft-delete subjects and chapters.\n";
    exit(0);
}

$now = now();

$subjectsUpdated = DB::table('subjects')
    ->whereIn('id', $subjectIds)
    ->update([
        'is_deleted' => true,
        'deleted_at' => $now,
        'updated_at' => $now,
    ]);

$chaptersUpdated = DB::table('chapters')
    ->whereIn('subject_id', $subjectIds)
    ->where('is_deleted', false)
    ->update([
        'is_deleted' => true,
        'deleted_at' => $now,
        'updated_at' => $now,
    ]);

echo "Soft-deleted subjects: {$subjectsUpdated}\n";
echo "Soft-deleted chapters: {$chaptersUpdated}\n";
echo "Image files were NOT removed from storage.\n";

$remaining = DB::table('subjects')
    ->where('challenge_type', 'school')
    ->where('is_deleted', false)
    ->count();

echo "Remaining active school subjects: {$remaining}\n";
