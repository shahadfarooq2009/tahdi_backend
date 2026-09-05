<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$restoredSubjects = DB::table('subjects')
    ->where('challenge_type', 'school')
    ->where('is_deleted', true)
    ->update(['is_deleted' => false, 'deleted_at' => null]);

$restoredQuestions = DB::table('questions')
    ->whereNull('category_id')
    ->where('is_deleted', true)
    ->update(['is_deleted' => false, 'deleted_at' => null]);

$schoolSubjectIds = DB::table('subjects')
    ->where('challenge_type', 'school')
    ->pluck('id');

$restoredChapters = DB::table('chapters')
    ->whereIn('subject_id', $schoolSubjectIds)
    ->where('is_deleted', true)
    ->update(['is_deleted' => false, 'deleted_at' => null]);

echo json_encode([
    'restored_subjects' => $restoredSubjects,
    'restored_questions' => $restoredQuestions,
    'restored_chapters' => $restoredChapters,
    'active_school_questions' => DB::table('questions')->where('is_deleted', false)->whereNull('category_id')->count(),
    'active_school_subjects' => DB::table('subjects')->where('challenge_type', 'school')->where('is_deleted', false)->count(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
