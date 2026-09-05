<?php

namespace App\Services\Game;

use App\Models\Category;
use App\Models\Chapter;
use App\Models\Question;
use App\Models\SchoolCourse;
use App\Models\Subject;
use App\Models\Textbook;
use App\Services\Admin\SchoolCourseService;
use App\Support\ArabicGradeMapper;
use App\Support\SubjectStageIcons;
use Illuminate\Support\Facades\DB;

class GameCatalogService
{
    public function __construct(
        private readonly SchoolUnitPlaySelectionService $unitPlaySelection,
        private readonly SchoolUnitProgressService $unitProgress,
    ) {}
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCategories(): array
    {
        return Category::query()
            ->where('is_deleted', false)
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => $category->toArray())
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listFamilySubjects(): array
    {
        return Subject::query()
            ->where('challenge_type', 'family')
            ->where('is_deleted', false)
            ->orderBy('name')
            ->get()
            ->map(fn (Subject $subject) => $subject->toArray())
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function familySubjectQuestionCounts(?string $userId = null): array
    {
        $subjects = $this->listFamilySubjects();
        $subjectIds = array_column($subjects, 'id');

        if ($subjectIds === []) {
            return [];
        }

        $viewedIds = [];
        if ($userId) {
            $viewedIds = DB::table('viewed_questions')
                ->where('user_id', $userId)
                ->pluck('question_id')
                ->all();
        }

        return $this->countFamilyQuestionsBySubject($subjectIds, $viewedIds);
    }

    /**
     * @param  array<int, string>  $subjectIds
     * @param  array<int, string>  $excludeQuestionIds
     * @return array<string, int>
     */
    private function countFamilyQuestionsBySubject(array $subjectIds, array $excludeQuestionIds = []): array
    {
        $questions = Question::query()
            ->select(['id', 'subject_id'])
            ->whereIn('subject_id', $subjectIds)
            ->whereNotNull('category_id')
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->get();

        $counts = array_fill_keys($subjectIds, 0);

        foreach ($questions as $question) {
            if ($question->subject_id === null) {
                continue;
            }

            if (in_array($question->id, $excludeQuestionIds, true)) {
                continue;
            }

            $counts[$question->subject_id] = ($counts[$question->subject_id] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function familySubjectUnviewedCounts(string $userId): array
    {
        return $this->familySubjectQuestionCounts($userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSchoolSubjects(?string $educationalStage = null, ?string $grade = null): array
    {
        $dbGrade = ArabicGradeMapper::gradeToDatabase($grade);
        $dbStage = ArabicGradeMapper::stageToDatabase($educationalStage);

        if (\Illuminate\Support\Facades\Schema::hasTable('school_units')) {
            if ($dbStage === 'high') {
                return $this->listHighSchoolSubjects($dbGrade, $dbStage);
            }

            $schoolUnitSubjectIds = $this->unitProgress->subjectIdsWithPlayableUnits($dbStage, $dbGrade);

            if ($schoolUnitSubjectIds === []) {
                return [];
            }

            return Subject::query()
                ->whereIn('id', $schoolUnitSubjectIds)
                ->where('challenge_type', 'school')
                ->where('is_deleted', false)
                ->orderBy('name')
                ->get()
                ->map(fn (Subject $subject) => $this->formatSchoolSubject($subject, $dbStage))
                ->all();
        }

        $questionSubjectIds = Question::query()
            ->select('chapters.subject_id')
            ->join('chapters', 'chapters.id', '=', 'questions.chapter_id')
            ->join('subjects', 'subjects.id', '=', 'chapters.subject_id')
            ->where('questions.is_deleted', false)
            ->where('questions.approval_status', 'approved')
            ->where('subjects.challenge_type', 'school')
            ->where('subjects.is_deleted', false)
            ->when($dbStage, fn ($query) => $query->where('questions.educational_stage', $dbStage))
            ->when($dbGrade, fn ($query) => $query->where('questions.grade', $dbGrade))
            ->distinct()
            ->pluck('chapters.subject_id')
            ->filter()
            ->all();

        $schoolUnitSubjectIds = [];

        if (\Illuminate\Support\Facades\Schema::hasTable('school_units')) {
            $schoolUnitSubjectIds = $this->unitProgress->subjectIdsWithPlayableUnits($dbStage, $dbGrade);
        }

        $textbookSubjectIds = [];

        if ($dbGrade) {
            $textbookQuery = Textbook::query()
                ->select('subject_id')
                ->where('structure_status', 'approved')
                ->where('grade', $dbGrade)
                ->whereNotNull('subject_id');

            $textbookStage = ArabicGradeMapper::textbookStageFromArabic($educationalStage);
            if ($textbookStage) {
                $textbookQuery->where('academic_stage', $textbookStage);
            }

            $textbookSubjectIds = $textbookQuery
                ->pluck('subject_id')
                ->filter()
                ->all();
        }

        $subjectIds = array_values(array_unique(array_merge($questionSubjectIds, $textbookSubjectIds, $schoolUnitSubjectIds)));

        if ($subjectIds === []) {
            return [];
        }

        return Subject::query()
            ->whereIn('id', $subjectIds)
            ->where('challenge_type', 'school')
            ->where('is_deleted', false)
            ->orderBy('name')
            ->get()
            ->map(fn (Subject $subject) => $this->formatSchoolSubject($subject, $dbStage))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSchoolSubject(Subject $subject, ?string $stage): array
    {
        $row = $subject->toArray();
        $row['icon_url'] = $this->normalizePublicAssetUrl(
            SubjectStageIcons::resolveIcon(
                $subject->icon_url,
                is_array($subject->stage_icons) ? $subject->stage_icons : null,
                $stage,
            )
        );

        return $row;
    }

    private function normalizePublicAssetUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return $url;
        }

        $trimmed = trim($url);

        if (str_starts_with($trimmed, '/storage/')) {
            return $trimmed;
        }

        $path = parse_url($trimmed, PHP_URL_PATH);

        if (is_string($path) && str_starts_with($path, '/storage/')) {
            return $path;
        }

        return $trimmed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listHighSchoolSubjects(?string $dbGrade, ?string $dbStage): array
    {
        return Subject::query()
            ->where('challenge_type', 'school')
            ->where('is_high_school_parent', true)
            ->where('is_deleted', false)
            ->orderBy('name')
            ->get()
            ->map(fn (Subject $subject) => $this->formatSchoolSubject($subject, $dbStage))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCoursesForSubject(
        string $subjectId,
        ?string $grade = null,
    ): array {
        $dbGrade = ArabicGradeMapper::gradeToDatabase($grade);
        $subject = Subject::query()
            ->where('id', $subjectId)
            ->where('challenge_type', 'school')
            ->where('is_deleted', false)
            ->first();

        if (! $subject || ! $subject->is_high_school_parent) {
            return [];
        }

        return SchoolCourse::query()
            ->where('parent_subject_id', $subjectId)
            ->when($dbGrade, function ($query) use ($dbGrade) {
                $query->where(function ($gradeQuery) use ($dbGrade) {
                    $gradeQuery
                        ->where('grade', $dbGrade)
                        ->orWhere('grade', SchoolCourseService::HIGH_SCHOOL_DEFAULT_GRADE);
                });
            })
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (SchoolCourse $course) => [
                'id' => $course->id,
                'parent_subject_id' => $course->parent_subject_id,
                'name' => $course->name,
                'code' => $course->code,
                'grade' => $course->grade,
                'display_order' => $course->display_order,
                'content_type' => 'school_course',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listChaptersForSubject(
        string $subjectName,
        ?string $educationalStage = null,
        ?string $grade = null,
        ?string $userId = null,
        ?string $subjectId = null,
        ?string $courseId = null,
    ): array {
        $subject = $this->resolveSchoolSubject($subjectId, $subjectName);

        if (! $subject) {
            return [];
        }

        $dbGrade = ArabicGradeMapper::gradeToDatabase($grade);
        $dbStage = ArabicGradeMapper::stageToDatabase($educationalStage);

        if (is_string($courseId) && trim($courseId) !== '') {
            $schoolUnits = $this->unitProgress->listUnitsForCourse(trim($courseId), $userId);
        } elseif ($subject->is_high_school_parent && $dbStage === 'high') {
            return [];
        } else {
            $schoolUnits = $this->unitProgress->listUnitsForSubject(
                $subject->id,
                $dbStage,
                $dbGrade,
                $userId,
            );
        }

        return array_map(function (array $unit) use ($subject) {
            return [
                'id' => $unit['chapter_id'] ?? $unit['id'],
                'school_unit_id' => $unit['id'],
                'chapter_id' => $unit['chapter_id'] ?? null,
                'name' => $unit['title'],
                'subject_id' => $subject->id,
                'chapter_number' => $unit['unit_number'] ?? null,
                'total_games' => $unit['total_games'] ?? 0,
                'completed_games' => $unit['completed_games'] ?? 0,
                'remaining_games' => $unit['remaining_games'] ?? 0,
                'is_completed' => $unit['is_completed'] ?? false,
                'remaining_label' => $this->buildRemainingGamesLabel(
                    (int) ($unit['remaining_games'] ?? 0),
                    (bool) ($unit['is_completed'] ?? false),
                ),
                'content_type' => 'school_unit',
            ];
        }, $schoolUnits);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listGamesForUnit(string $unitId, ?string $userId): array
    {
        return $this->unitProgress->listGamesForUnit($unitId, $userId);
    }

    private function buildRemainingGamesLabel(int $remainingGames, bool $isCompleted): string
    {
        if ($isCompleted) {
            return 'مكتمل';
        }

        if ($remainingGames <= 0) {
            return 'غير جاهز';
        }

        return "متبقي {$remainingGames}";
    }

    private function resolveSchoolSubject(?string $subjectId, string $subjectName): ?Subject
    {
        if (is_string($subjectId) && trim($subjectId) !== '') {
            $subject = Subject::query()
                ->where('id', trim($subjectId))
                ->where('challenge_type', 'school')
                ->where('is_deleted', false)
                ->first();

            if ($subject) {
                return $subject;
            }
        }

        $target = $this->normalizeSubjectName($subjectName);

        if ($target === '') {
            return null;
        }

        return Subject::query()
            ->where('challenge_type', 'school')
            ->where('is_deleted', false)
            ->get()
            ->first(fn (Subject $subject) => $this->normalizeSubjectName($subject->name) === $target);
    }

    private function normalizeSubjectName(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Chapter>  $chapters
     * @return array<int, array<string, mixed>>
     */
    private function mapChaptersWithPlayAvailability($chapters, ?string $userId): array
    {
        return $chapters
            ->map(function (Chapter $chapter) use ($userId) {
                $row = $chapter->toArray();

                if ($userId) {
                    $availability = $this->unitPlaySelection->getChapterPlayAvailability($userId, $chapter->id);
                    $row['remaining_plays'] = $availability['remaining_plays'] ?? 0;
                    $row['remaining_label'] = $availability['remaining_label'] ?? null;
                    $row['play_status'] = $availability['status'] ?? null;
                    $row['total_approved_questions'] = $availability['total_approved_questions'] ?? 0;
                }

                return $row;
            })
            ->all();
    }
}
