<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Question;
use App\Models\SchoolUnit;
use Illuminate\Support\Facades\DB;

$withGame = Question::query()
    ->where('question_source', 'excel')
    ->where('is_deleted', false)
    ->whereNotNull('game_id')
    ->count();

$unitsWithCourse = SchoolUnit::query()->whereNotNull('course_id')->count();
$unitsWithoutCourse = SchoolUnit::query()->whereNull('course_id')->count();

echo "excel_with_game={$withGame}\n";
echo "units_with_course={$unitsWithCourse}\n";
echo "units_without_course={$unitsWithoutCourse}\n";

$sample = DB::table('questions as q')
    ->join('school_games as g', 'g.id', '=', 'q.game_id')
    ->join('school_units as u', 'u.id', '=', 'g.unit_id')
    ->leftJoin('school_courses as c', 'c.id', '=', 'u.course_id')
    ->where('q.question_source', 'excel')
    ->where('q.is_deleted', false)
    ->select('c.name as course_name', 'u.title as unit_title', DB::raw('count(*) as cnt'))
    ->groupBy('c.name', 'u.title')
    ->orderByDesc('cnt')
    ->limit(10)
    ->get();

foreach ($sample as $row) {
    echo "group | course={$row->course_name} | unit={$row->unit_title} | cnt={$row->cnt}\n";
}
