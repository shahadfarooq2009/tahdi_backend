<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Question;
use App\Models\SchoolCourse;
use App\Models\Subject;

$excelTotal = Question::query()->where('question_source', 'excel')->where('is_deleted', false)->count();
$excelWithSource = Question::query()
    ->where('question_source', 'excel')
    ->where('is_deleted', false)
    ->whereNotNull('import_source')
    ->where('import_source', '!=', '')
    ->count();

echo "excel_total={$excelTotal}\n";
echo "excel_with_source={$excelWithSource}\n";

$courses = SchoolCourse::query()
    ->with('parentSubject:id,name')
    ->withCount('units')
    ->orderBy('name')
    ->get(['id', 'name', 'parent_subject_id', 'import_source']);

foreach ($courses as $course) {
  $parent = $course->parentSubject?->name ?? '?';
  $source = $course->import_source ?: '-';
  echo "course | {$parent} | {$course->name} | units={$course->units_count} | source={$source} | {$course->id}\n";
}

$subjects = Subject::query()
    ->where('challenge_type', 'school')
    ->where('is_deleted', false)
    ->where('is_high_school_parent', true)
    ->get(['id', 'name']);

foreach ($subjects as $subject) {
    echo "parent_subject | {$subject->name} | {$subject->id}\n";
}
