<?php

namespace App\Console\Commands;

use App\Models\Question;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteHighSchoolExcelQuestionsCommand extends Command
{
    protected $signature = 'school:delete-high-school-excel-questions
        {--dry-run : Show how many questions would be deleted without deleting}
        {--yes : Skip confirmation prompt}
        {--force : Allow running outside local/testing environments}';

    protected $description = 'Permanently delete all high school questions imported from Excel';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('force')) {
            $this->error('Refusing to run outside local/testing. Re-run with --force if you are sure.');

            return self::FAILURE;
        }

        $query = Question::query()
            ->where('question_source', 'excel')
            ->where('educational_stage', 'high');

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No high school Excel questions found.');

            return self::SUCCESS;
        }

        $byCourse = DB::table('questions as q')
            ->join('school_games as g', 'g.id', '=', 'q.game_id')
            ->join('school_units as u', 'u.id', '=', 'g.unit_id')
            ->leftJoin('school_courses as c', 'c.id', '=', 'u.course_id')
            ->where('q.question_source', 'excel')
            ->where('q.educational_stage', 'high')
            ->select('c.name', DB::raw('count(*) as c'))
            ->groupBy('c.name')
            ->orderBy('c.name')
            ->get();

        $this->line("Found {$count} high school Excel questions:");
        foreach ($byCourse as $row) {
            $this->line("  - {$row->name}: {$row->c}");
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run only. No questions were deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm("Permanently delete all {$count} questions?", true)) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        $deleted = 0;

        (clone $query)
            ->select('id')
            ->orderBy('id')
            ->chunkById(250, function ($questions) use (&$deleted) {
                $ids = $questions->pluck('id')->all();
                $deleted += Question::query()->whereIn('id', $ids)->delete();
            });

        $this->info("Deleted {$deleted} high school Excel questions.");

        return self::SUCCESS;
    }
}
