<?php

namespace App\Services\Admin;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\SchoolCourse;
use App\Models\Subject;
use App\Support\ArabicGradeMapper;
use App\Support\Grades;
use App\Support\Roles;

class SchoolCourseService
{
    public const HIGH_SCHOOL_DEFAULT_GRADE = 'high_school';

    public const HIGH_SCHOOL_GRADES = ['grade10', 'grade11', 'grade12', self::HIGH_SCHOOL_DEFAULT_GRADE];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForParent(string $parentSubjectId, ?string $grade = null): array
    {
        $this->assertHighSchoolParent($parentSubjectId);

        $query = SchoolCourse::query()
            ->where('parent_subject_id', $parentSubjectId)
            ->orderBy('display_order')
            ->orderBy('name');

        if (is_string($grade) && $grade !== '') {
            $query->where('grade', $this->normalizeDbGrade($grade));
        }

        return $query->get()->map(fn (SchoolCourse $course) => $this->formatCourse($course))->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function create(string $parentSubjectId, array $payload, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canAddSubjects')) {
            throw new ForbiddenException();
        }

        $parent = $this->assertHighSchoolParent($parentSubjectId);
        $grade = $this->normalizeDbGrade((string) ($payload['grade'] ?? self::HIGH_SCHOOL_DEFAULT_GRADE));

        if (! is_string($grade) || ! in_array($grade, self::HIGH_SCHOOL_GRADES, true)) {
            $grade = self::HIGH_SCHOOL_DEFAULT_GRADE;
        }

        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new ValidationException('اسم المقرر مطلوب.');
        }

        $exists = SchoolCourse::query()
            ->where('parent_subject_id', $parentSubjectId)
            ->where('grade', $grade)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw new ConflictException('يوجد مقرر بنفس الاسم لهذه المادة والصف.');
        }

        $course = SchoolCourse::query()->create([
            'parent_subject_id' => $parent->id,
            'name' => $name,
            'code' => isset($payload['code']) ? trim((string) $payload['code']) : null,
            'grade' => $grade,
            'display_order' => (int) ($payload['display_order'] ?? 0),
        ]);

        return $this->formatCourse($course);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function update(string $courseId, array $payload, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canEditSubjects')) {
            throw new ForbiddenException();
        }

        $course = $this->findCourse($courseId);
        $data = [];

        if (array_key_exists('name', $payload) && is_string($payload['name'])) {
            $name = trim($payload['name']);

            if ($name === '') {
                throw new ValidationException('اسم المقرر مطلوب.');
            }

            $data['name'] = $name;
        }

        if (array_key_exists('code', $payload)) {
            $data['code'] = is_string($payload['code']) && trim($payload['code']) !== ''
                ? trim($payload['code'])
                : null;
        }

        if (array_key_exists('display_order', $payload)) {
            $data['display_order'] = (int) $payload['display_order'];
        }

        if ($data !== []) {
            $course->update($data);
        }

        return $this->formatCourse($course->fresh());
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array{id: string, deleted: true}
     */
    public function delete(string $courseId, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canDeleteSubjects')) {
            throw new ForbiddenException();
        }

        $course = $this->findCourse($courseId);
        $course->delete();

        return ['id' => $courseId, 'deleted' => true];
    }

    private function findCourse(string $courseId): SchoolCourse
    {
        $course = SchoolCourse::query()->find($courseId);

        if (! $course) {
            throw new NotFoundException('Course not found');
        }

        return $course;
    }

    private function assertHighSchoolParent(string $parentSubjectId): Subject
    {
        $subject = Subject::query()
            ->where('id', $parentSubjectId)
            ->where('challenge_type', 'school')
            ->where('is_deleted', false)
            ->first();

        if (! $subject) {
            throw new NotFoundException('Subject not found');
        }

        if (! $subject->is_high_school_parent) {
            throw new ValidationException('هذه المادة ليست مادة ثانوية أساسية.');
        }

        return $subject;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatCourse(SchoolCourse $course): array
    {
        return [
            'id' => $course->id,
            'parent_subject_id' => $course->parent_subject_id,
            'name' => $course->name,
            'code' => $course->code,
            'grade' => $course->grade,
            'display_order' => $course->display_order,
            'created_at' => $course->created_at?->toISOString(),
            'updated_at' => $course->updated_at?->toISOString(),
        ];
    }

    private function normalizeDbGrade(?string $grade): ?string
    {
        if (! is_string($grade) || trim($grade) === '') {
            return null;
        }

        $fromArabic = ArabicGradeMapper::gradeToDatabase($grade);

        if ($fromArabic) {
            return $fromArabic;
        }

        $normalized = Grades::normalize($grade);

        if (is_string($normalized) && preg_match('/^grade_(\d{1,2})$/', $normalized, $matches)) {
            return 'grade'.(int) $matches[1];
        }

        return trim($grade);
    }
}
