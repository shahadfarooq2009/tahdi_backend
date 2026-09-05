<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$byStage = DB::table('questions')
    ->where('question_source', 'excel')
    ->where('is_deleted', false)
    ->select('educational_stage', DB::raw('count(*) as c'))
    ->groupBy('educational_stage')
    ->get();

echo "excel_by_stage:\n";
foreach ($byStage as $row) {
    echo "  {$row->educational_stage}={$row->c}\n";
}

$highViaCourse = DB::table('questions as q')
    ->join('school_games as g', 'g.id', '=', 'q.game_id')
    ->join('school_units as u', 'u.id', '=', 'g.unit_id')
    ->where('q.question_source', 'excel')
    ->where('q.is_deleted', false)
    ->whereNotNull('u.course_id')
    ->count();

$highStage = DB::table('questions')
    ->where('question_source', 'excel')
    ->where('is_deleted', false)
    ->where('educational_stage', 'high')
    ->count();

echo "high_stage_excel={$highStage}\n";
echo "high_via_course_excel={$highViaCourse}\n";

$courses = DB::table('questions as q')
    ->join('school_games as g', 'g.id', '=', 'q.game_id')
    ->join('school_units as u', 'u.id', '=', 'g.unit_id')
    ->leftJoin('school_courses as c', 'c.id', '=', 'u.course_id')
    ->where('q.question_source', 'excel')
    ->where('q.is_deleted', false)
    ->whereNotNull('u.course_id')
    ->select('c.name', DB::raw('count(*) as c'))
    ->groupBy('c.name')
    ->orderBy('c.name')
    ->get();

echo "by_course:\n";
foreach ($courses as $row) {
    echo "  {$row->name}={$row->c}\n";
}
