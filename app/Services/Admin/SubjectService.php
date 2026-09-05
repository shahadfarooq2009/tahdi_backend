<?php

namespace App\Services\Admin;

use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\Category;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SubjectGrade;
use App\Support\Roles;
use App\Support\SubjectStageIcons;
use Illuminate\Database\Eloquent\Builder;

class SubjectService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters): array
    {
        $query = Subject::query()
            ->with('subjectGrades:subject_id,grade,is_completed')
            ->where('is_deleted', (bool) ($filters['is_deleted'] ?? false));

        if ($filters['is_deleted'] ?? false) {
            $query->orderByDesc('deleted_at');
        } else {
            $query->orderBy('name');
        }

        if (! empty($filters['challenge_type'])) {
            $query->where('challenge_type', $filters['challenge_type']);
        }

        if (! empty($filters['search'])) {
            $query->where('name', 'ilike', '%'.$filters['search'].'%');
        }

        if (($filters['school_stage_scope'] ?? null) === 'high_school') {
            $query->where('is_high_school_parent', true);
        } elseif (($filters['school_stage_scope'] ?? null) === 'primary_middle') {
            $query->where('is_high_school_parent', false);
        }

        $withCourses = ($filters['school_stage_scope'] ?? null) === 'high_school'
            && \Illuminate\Support\Facades\Schema::hasTable('school_courses');

        if ($withCourses) {
            $query->with('schoolCourses');
        }

        $subjects = $query->get();
        $categoryMap = $this->loadCategoryMap($subjects->pluck('category_id')->filter()->all());

        return $subjects->map(fn (Subject $s) => $this->formatSubject($s, $categoryMap))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getById(string $subjectId): array
    {
        $subject = Subject::query()
            ->with('subjectGrades:subject_id,grade,is_completed')
            ->where('id', $subjectId)
            ->first();

        if (! $subject) {
            throw new NotFoundException('Subject not found');
        }

        $categoryMap = $this->loadCategoryMap([$subject->category_id]);

        return $this->formatSubject($subject, $categoryMap);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function create(array $payload, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canAddSubjects')) {
            throw new ForbiddenException();
        }

        $grades = $payload['grades'] ?? null;
        unset($payload['grades']);

        $payload = $this->normalizeSubjectPayload($payload);

        if (($payload['challenge_type'] ?? null) === 'school' && is_array($grades) && $grades !== []) {
            SubjectStageIcons::assertRequiredStageIcons(
                $grades,
                is_array($payload['stage_icons'] ?? null) ? $payload['stage_icons'] : null,
                $payload['icon_url'] ?? null,
            );
        }

        if (($payload['challenge_type'] ?? null) === 'family' && ! empty($payload['category_id'])) {
            $this->assertCategoryExists($payload['category_id']);
        }

        $this->assertSubjectNameUnique(
            $payload['name'],
            $payload['challenge_type'],
            null,
            (bool) ($payload['is_high_school_parent'] ?? false),
        );

        $subject = Subject::query()->create($payload);

        if (($payload['challenge_type'] ?? null) === 'school' && is_array($grades) && $grades !== []) {
            $this->replaceSubjectGrades($subject->id, $grades);

            return $this->getById($subject->id);
        }

        $categoryMap = $this->loadCategoryMap([$subject->category_id]);

        return $this->formatSubject($subject->load('subjectGrades'), $categoryMap);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function update(string $subjectId, array $payload, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canEditSubjects')) {
            throw new ForbiddenException();
        }

        $existing = $this->getById($subjectId);
        $grades = $payload['grades'] ?? null;
        unset($payload['grades']);

        $payload = $this->normalizeSubjectPayload($payload);

        if (array_key_exists('name', $payload)) {
            $normalizedName = $payload['name'];
            $existingTrimmed = trim((string) $existing['name']);

            if ($normalizedName === $existingTrimmed) {
                unset($payload['name']);
            } else {
                $this->assertSubjectNameUnique(
                    $normalizedName,
                    $payload['challenge_type'] ?? $existing['challenge_type'],
                    $subjectId,
                    (bool) ($payload['is_high_school_parent'] ?? $existing['is_high_school_parent'] ?? false),
                );
                $payload['name'] = $normalizedName;
            }
        }

        if (! empty($payload['category_id'])) {
            $this->assertCategoryExists($payload['category_id']);
        }

        $challengeType = $payload['challenge_type'] ?? $existing['challenge_type'];

        if ($challengeType === 'school' && is_array($grades) && $grades !== []) {
            SubjectStageIcons::assertRequiredStageIcons(
                $grades,
                is_array($payload['stage_icons'] ?? null)
                    ? $payload['stage_icons']
                    : (is_array($existing['stage_icons'] ?? null) ? $existing['stage_icons'] : null),
                $payload['icon_url'] ?? $existing['icon_url'] ?? null,
            );
        }

        $payload['updated_at'] = now();
        Subject::query()->where('id', $subjectId)->update($payload);

        if ($grades !== null) {
            $this->replaceSubjectGrades($subjectId, $grades);
        }

        return $this->getById($subjectId);
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array{id: string, deleted: true}
     */
    public function softDelete(string $subjectId, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canDeleteSubjects')) {
            throw new ForbiddenException();
        }

        $this->getById($subjectId);
        \DB::select('SELECT soft_delete_subject(?, ?)', [$subjectId, $actor['actorUserId']]);

        return ['id' => $subjectId, 'deleted' => true];
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array{id: string, permanently_deleted: true}
     */
    public function permanentlyDelete(string $subjectId, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canDeleteSubjects')) {
            throw new ForbiddenException();
        }

        Subject::query()->where('id', $subjectId)->delete();

        return ['id' => $subjectId, 'permanently_deleted' => true];
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function restore(string $subjectId, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canEditSubjects')) {
            throw new ForbiddenException();
        }

        \DB::select('SELECT restore_subject(?)', [$subjectId]);

        return $this->getById($subjectId);
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function updateGradeMapping(string $subjectId, string $grade, bool $remove, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canEditSubjects')) {
            throw new ForbiddenException();
        }

        $this->getById($subjectId);

        if ($remove) {
            SubjectGrade::query()
                ->where('subject_id', $subjectId)
                ->where('grade', $grade)
                ->delete();

            return ['subject_id' => $subjectId, 'grade' => $grade, 'removed' => true];
        }

        SubjectGrade::query()->updateOrCreate(
            ['subject_id' => $subjectId, 'grade' => $grade],
            ['grade' => $grade]
        );

        return ['subject_id' => $subjectId, 'grade' => $grade, 'updated' => true];
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array{subject_id: string, grade: string, is_completed: bool}
     */
    public function toggleCompletion(string $subjectId, string $grade, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canEditSubjects')) {
            throw new ForbiddenException();
        }

        $this->getById($subjectId);

        $result = \DB::selectOne(
            'SELECT toggle_subject_completion(?::uuid, ?) AS is_completed',
            [$subjectId, $grade]
        );

        return [
            'subject_id' => $subjectId,
            'grade' => $grade,
            'is_completed' => (bool) ($result->is_completed ?? false),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function questionStats(): array
    {
        $subjects = Subject::query()
            ->with('subjectGrades:subject_id,grade,is_completed')
            ->where('challenge_type', 'school')
            ->where('is_deleted', false)
            ->orderBy('name')
            ->get();

        $questionRows = Question::query()
            ->select(['subject_id', 'grade', 'approval_status'])
            ->where('is_deleted', false)
            ->whereNotNull('subject_id')
            ->whereNotNull('grade')
            ->get();

        $countsByKey = [];

        foreach ($questionRows as $row) {
            $key = $row->subject_id.'::'.$row->grade;
            $current = $countsByKey[$key] ?? ['total' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
            $current['total']++;
            if ($row->approval_status === 'approved') {
                $current['approved']++;
            } elseif ($row->approval_status === 'pending') {
                $current['pending']++;
            } elseif ($row->approval_status === 'rejected') {
                $current['rejected']++;
            }
            $countsByKey[$key] = $current;
        }

        return $subjects->map(function (Subject $subject) use ($countsByKey) {
            return [
                'id' => $subject->id,
                'name' => $subject->name,
                'category_id' => $subject->category_id,
                'challenge_type' => $subject->challenge_type,
                'subject_grades' => $this->mapSubjectGrades($subject, $countsByKey)->all(),
            ];
        })->all();
    }

    /**
     * @param  string[]  $categoryIds
     * @return array<string, array{id: string, name: string}>
     */
    private function loadCategoryMap(array $categoryIds): array
    {
        $unique = array_values(array_unique(array_filter($categoryIds)));

        if ($unique === []) {
            return [];
        }

        return Category::query()
            ->whereIn('id', $unique)
            ->get(['id', 'name'])
            ->keyBy('id')
            ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    /**
     * @param  array<string, array{id: string, name: string}>  $categoryMap
     * @return array<string, mixed>
     */
    private function formatSubject(Subject $subject, array $categoryMap): array
    {
        $row = $subject->toArray();
        $row['subject_grades'] = $this->mapSubjectGrades($subject)->map(fn (array $grade) => [
            'grade' => $grade['grade'],
            'is_completed' => $grade['is_completed'],
        ])->all();
        $row['categories'] = $subject->category_id
            ? ($categoryMap[$subject->category_id] ?? null)
            : null;

        if ($subject->relationLoaded('schoolCourses')) {
            $row['school_courses'] = $subject->schoolCourses
                ->map(fn ($course) => [
                    'id' => $course->id,
                    'name' => $course->name,
                    'code' => $course->code,
                    'grade' => $course->grade,
                    'display_order' => $course->display_order,
                ])
                ->values()
                ->all();
        }

        unset($row['schoolCourses']);

        unset($row['grades']);

        return $row;
    }

    /**
     * @param  array<string, array{total: int, approved: int, pending: int, rejected: int}>|null  $countsByKey
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function mapSubjectGrades(Subject $subject, ?array $countsByKey = null): \Illuminate\Support\Collection
    {
        $grades = $subject->relationLoaded('subjectGrades')
            ? $subject->getRelation('subjectGrades')
            : $subject->subjectGrades()->get();

        return $grades->map(function (SubjectGrade $grade) use ($subject, $countsByKey) {
            $row = [
                'grade' => $grade->grade,
                'is_completed' => $grade->is_completed,
            ];

            if ($countsByKey !== null) {
                $key = $subject->id.'::'.$grade->grade;
                $counts = $countsByKey[$key] ?? ['total' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
                $row['question_count'] = $counts['total'];
                $row['approved'] = $counts['approved'];
                $row['pending'] = $counts['pending'];
                $row['rejected'] = $counts['rejected'];
            }

            return $row;
        });
    }

    private function replaceSubjectGrades(string $subjectId, array $grades): void
    {
        SubjectGrade::query()->where('subject_id', $subjectId)->delete();

        foreach ($grades as $grade) {
            SubjectGrade::query()->create([
                'subject_id' => $subjectId,
                'grade' => $grade,
            ]);
        }
    }

    private function assertCategoryExists(string $categoryId): void
    {
        $exists = Category::query()
            ->where('id', $categoryId)
            ->where('is_deleted', false)
            ->exists();

        if (! $exists) {
            throw new NotFoundException('Category not found');
        }
    }

    private function assertSubjectNameUnique(
        string $name,
        string $challengeType,
        ?string $excludeId = null,
        ?bool $isHighSchoolParent = null,
    ): void
    {
        $normalizedName = trim($name);

        if ($normalizedName === '') {
            throw new ConflictException('اسم المادة مطلوب');
        }

        $query = Subject::query()
            ->whereRaw('TRIM(name) = ?', [$normalizedName])
            ->where('challenge_type', $challengeType)
            ->where('is_deleted', false);

        if ($challengeType === 'school' && $isHighSchoolParent !== null) {
            $query->where('is_high_school_parent', $isHighSchoolParent);
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            $scopeLabel = $challengeType === 'school'
                ? ($isHighSchoolParent ? 'المرحلة الثانوية' : 'المراحل الابتدائية والإعدادية')
                : 'تحدي العائلات';

            throw new ConflictException(
                "يوجد مادة أخرى بنفس الاسم في {$scopeLabel}. يمكنك استخدام نفس الاسم في مرحلة مختلفة فقط."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeSubjectPayload(array $payload): array
    {
        if (array_key_exists('name', $payload) && is_string($payload['name'])) {
            $payload['name'] = trim($payload['name']);
        }

        if (array_key_exists('stage_icons', $payload) && is_array($payload['stage_icons'])) {
            $payload['stage_icons'] = array_filter(
                $payload['stage_icons'],
                static fn ($value) => is_string($value) && trim($value) !== ''
            );
        }

        return $payload;
    }
}
