<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$courseId = '01a05dde-4716-7225-91d6-62475fbac79b';

$cnt = DB::table('questions as q')
    ->join('school_games as g', 'g.id', '=', 'q.game_id')
    ->join('school_units as u', 'u.id', '=', 'g.unit_id')
    ->where('u.course_id', $courseId)
    ->where('q.question_source', 'excel')
    ->where('q.is_deleted', false)
    ->count();

echo "dine101_questions={$cnt}\n";

$highSchool = DB::table('questions as q')
    ->join('school_games as g', 'g.id', '=', 'q.game_id')
    ->join('school_units as u', 'u.id', '=', 'g.unit_id')
    ->whereNotNull('u.course_id')
    ->where('q.question_source', 'excel')
    ->where('q.is_deleted', false)
    ->count();

$noCourse = DB::table('questions as q')
    ->join('school_games as g', 'g.id', '=', 'q.game_id')
    ->join('school_units as u', 'u.id', '=', 'g.unit_id')
    ->whereNull('u.course_id')
    ->where('q.question_source', 'excel')
    ->where('q.is_deleted', false)
    ->count();

echo "high_school_excel={$highSchool}\n";
echo "primary_middle_excel={$noCourse}\n";
