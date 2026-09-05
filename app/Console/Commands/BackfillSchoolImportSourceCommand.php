<?php

namespace App\Console\Commands;

use App\Models\Question;
use App\Models\SchoolCourse;
use App\Models\SchoolGame;
use App\Models\SchoolUnit;
use Illuminate\Console\Command;

class BackfillSchoolImportSourceCommand extends Command
{
    protected $signature = 'school:backfill-import-source
        {filename : Excel file name to store as import source}
        {--course-id= : Limit to a specific school course id}
        {--subject-id= : Limit to a parent subject id}';

    protected $description = 'Backfill import_source on school units and excel questions with the uploaded file name';

    public function handle(): int
    {
        $filename = trim((string) $this->argument('filename'));

        if ($filename === '') {
            $this->error('Filename is required.');

            return self::FAILURE;
        }

        $unitsQuery = SchoolUnit::query()->whereNotNull('course_id');

        if ($courseId = $this->option('course-id')) {
            $unitsQuery->where('course_id', $courseId);
        }

        if ($subjectId = $this->option('subject-id')) {
            $courseIds = SchoolCourse::query()
                ->where('parent_subject_id', $subjectId)
                ->pluck('id')
                ->all();

            if ($courseIds === []) {
                $this->error('No courses found for the given subject id.');

                return self::FAILURE;
            }

            $unitsQuery->whereIn('course_id', $courseIds);
        }

        $unitIds = $unitsQuery->pluck('id')->all();

        if ($unitIds === []) {
            $this->error('No school units matched the provided filters.');

            return self::FAILURE;
        }

        $unitsUpdated = SchoolUnit::query()
            ->whereIn('id', $unitIds)
            ->update(['import_source' => $filename]);

        if ($courseId = $this->option('course-id')) {
            SchoolCourse::query()
                ->where('id', $courseId)
                ->update(['import_source' => $filename]);
        } elseif ($subjectId = $this->option('subject-id')) {
            $courseIds = SchoolCourse::query()
                ->where('parent_subject_id', $subjectId)
                ->pluck('id')
                ->all();

            if ($courseIds !== []) {
                SchoolCourse::query()
                    ->whereIn('id', $courseIds)
                    ->update(['import_source' => $filename]);
            }
        }

        $gameIds = SchoolGame::query()
            ->whereIn('unit_id', $unitIds)
            ->pluck('id')
            ->all();

        $questionsUpdated = 0;

        if ($gameIds !== []) {
            $questionsUpdated = Question::query()
                ->whereIn('game_id', $gameIds)
                ->where('question_source', 'excel')
                ->update(['import_source' => $filename]);
        }

        $this->info("Updated {$unitsUpdated} units and {$questionsUpdated} excel questions with source: {$filename}");

        return self::SUCCESS;
    }
}
