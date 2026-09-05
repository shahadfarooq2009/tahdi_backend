<?php

namespace App\Services\Admin;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\Question;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class QuestionService
{
    private static ?bool $questionsHasImportSource = null;

    private static ?bool $unitsHaveImportSource = null;

    private static ?bool $coursesHaveImportSource = null;

    public function __construct(
        private readonly ChapterService $chapterService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters): array
    {
        $query = $this->baseQuery()
            ->where('is_deleted', (bool) ($filters['is_deleted'] ?? false));

        if ($filters['is_deleted'] ?? false) {
            $query->orderByDesc('deleted_at');
        } else {
            $query->orderByDesc('created_at');
        }

        $this->applyFilters($query, $filters);

        return $query->get()->map(fn (Question $q) => $this->formatQuestion($q))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getById(string $questionId): array
    {
        $question = $this->baseQuery()->where('id', $questionId)->first();

        if (! $question) {
            throw new NotFoundException('Question not found');
        }

        return $this->formatQuestion($question);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function create(array $payload, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canAddQuestions')) {
            throw new ForbiddenException();
        }

        $data = $this->buildWriteData($payload, $actor, true);
        $question = Question::query()->create($data);

        return $this->getById($question->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function update(string $questionId, array $payload, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canEditQuestions')) {
            throw new ForbiddenException();
        }

        $this->getById($questionId);
        $data = $this->buildWriteData($payload, $actor, false);
        $data['updated_at'] = now();

        Question::query()->where('id', $questionId)->update($data);

        return $this->getById($questionId);
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array{id: string, deleted: true}
     */
    public function softDelete(string $questionId, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canDeleteQuestions')) {
            throw new ForbiddenException();
        }

        \DB::select('SELECT soft_delete_question(?, ?)', [$questionId, $actor['actorUserId']]);

        return ['id' => $questionId, 'deleted' => true];
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array{id: string, permanently_deleted: true}
     */
    public function permanentlyDelete(string $questionId, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canDeleteQuestions')) {
            throw new ForbiddenException();
        }

        Question::query()->where('id', $questionId)->delete();

        return ['id' => $questionId, 'permanently_deleted' => true];
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function restore(string $questionId, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canEditQuestions')) {
            throw new ForbiddenException();
        }

        \DB::select('SELECT restore_question(?)', [$questionId]);

        return $this->getById($questionId);
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function approve(string $questionId, array $actor): array
    {
        return $this->updateApproval($questionId, $actor, [
            'approval_status' => 'approved',
            'approved_by' => $actor['actorUserId'],
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    /**
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    public function reject(string $questionId, ?string $reason, array $actor): array
    {
        return $this->updateApproval($questionId, $actor, [
            'approval_status' => 'rejected',
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * @param  string[]  $ids
     * @return array<int, array<string, mixed>>
     */
    public function bulkApprove(array $ids, array $actor): array
    {
        return array_map(fn (string $id) => $this->approve($id, $actor), $ids);
    }

    /**
     * @param  string[]  $ids
     * @return array<int, array<string, mixed>>
     */
    public function bulkReject(array $ids, ?string $reason, array $actor): array
    {
        return array_map(fn (string $id) => $this->reject($id, $reason, $actor), $ids);
    }

    /**
     * @param  string[]  $ids
     * @return array<int, array{id: string, deleted: true}>
     */
    public function bulkSoftDelete(array $ids, array $actor): array
    {
        return array_map(fn (string $id) => $this->softDelete($id, $actor), $ids);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{id: string}>
     */
    public function import(array $rows, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canAddQuestions')) {
            throw new ForbiddenException();
        }

        $now = now();
        $prepared = array_map(function (array $row) use ($actor, $now) {
            $base = array_merge($row, [
                'submitted_by' => $actor['actorUserId'],
                'submitted_at' => $now,
            ]);

            if ($actor['actorRole'] === 'admin') {
                return array_merge($base, [
                    'approval_status' => 'approved',
                    'approved_by' => $actor['actorUserId'],
                    'approved_at' => $now,
                ]);
            }

            return array_merge($base, ['approval_status' => 'pending']);
        }, $rows);

        $ids = [];

        foreach ($prepared as $row) {
            $created = Question::query()->create($row);
            $ids[] = ['id' => $created->id];
        }

        return $ids;
    }

    private function baseQuery(): Builder
    {
        $unitColumns = ['id', 'course_id', 'title'];
        $courseColumns = ['id', 'name'];

        if ($this->unitsHaveImportSourceColumn()) {
            $unitColumns[] = 'import_source';
        }

        if ($this->coursesHaveImportSourceColumn()) {
            $courseColumns[] = 'import_source';
        }

        return Question::query()
            ->with([
                'chapter:id,name,chapter_number,subject_id',
                'category:id,name',
                'subject:id,name',
                'submitter:id,full_name,email',
                'game:id,unit_id,title,game_number',
                'game.unit:'.implode(',', $unitColumns),
                'game.unit.course:'.implode(',', $courseColumns),
            ]);
    }

    /**
     * @param  Builder<Question>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach (['subject_id', 'grade', 'unit', 'chapter_id', 'approval_status', 'question_source', 'submitted_by'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['points_value'])) {
            $query->where('points_value', (int) $filters['points_value']);
        }

        if (! empty($filters['difficulty_level'])) {
            $query->where('difficulty_level', (int) $filters['difficulty_level']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('question_text', 'ilike', $search)
                    ->orWhere('answer_text', 'ilike', $search);
            });
        }

        if (array_key_exists('has_category', $filters) && $filters['has_category'] !== null && $filters['has_category'] !== '') {
            if (filter_var($filters['has_category'], FILTER_VALIDATE_BOOLEAN)) {
                $query->whereNotNull('category_id');
            } else {
                $query->whereNull('category_id');
            }
        }

        if (! empty($filters['exclude_question_sources']) && is_array($filters['exclude_question_sources'])) {
            $query->whereNotIn('question_source', $filters['exclude_question_sources']);
        }
    }

    private function questionsHaveImportSourceColumn(): bool
    {
        if (self::$questionsHasImportSource === null) {
            self::$questionsHasImportSource = Schema::hasColumn('questions', 'import_source');
        }

        return self::$questionsHasImportSource;
    }

    private function unitsHaveImportSourceColumn(): bool
    {
        if (self::$unitsHaveImportSource === null) {
            self::$unitsHaveImportSource = Schema::hasColumn('school_units', 'import_source');
        }

        return self::$unitsHaveImportSource;
    }

    private function coursesHaveImportSourceColumn(): bool
    {
        if (self::$coursesHaveImportSource === null) {
            self::$coursesHaveImportSource = Schema::hasColumn('school_courses', 'import_source');
        }

        return self::$coursesHaveImportSource;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatQuestion(Question $question): array
    {
        $row = $question->toArray();

        $row['chapters'] = $question->chapter ? [
            'id' => $question->chapter->id,
            'name' => $question->chapter->name,
            'chapter_number' => $question->chapter->chapter_number,
            'subject_id' => $question->chapter->subject_id,
        ] : null;

        $row['categories'] = $question->category ? ['name' => $question->category->name] : null;
        $row['subjects'] = $question->subject ? ['name' => $question->subject->name] : null;
        $row['user_profiles'] = $question->submitter ? [
            'full_name' => $question->submitter->full_name,
            'email' => $question->submitter->email,
        ] : null;
        $row['import_source_label'] = $this->resolveImportSourceLabel($question);
        $row['course_name'] = $this->resolveCourseName($question);

        unset($row['chapter'], $row['category'], $row['subject'], $row['submitter'], $row['game']);

        return $row;
    }

    private function resolveImportSourceLabel(Question $question): ?string
    {
        if (
            $this->questionsHaveImportSourceColumn()
            && is_string($question->import_source)
            && trim($question->import_source) !== ''
        ) {
            return trim($question->import_source);
        }

        $unitSource = $question->game?->unit?->import_source;

        if (is_string($unitSource) && trim($unitSource) !== '') {
            return trim($unitSource);
        }

        $courseSource = $question->game?->unit?->course?->import_source;

        if (is_string($courseSource) && trim($courseSource) !== '') {
            return trim($courseSource);
        }

        return null;
    }

    private function resolveCourseName(Question $question): ?string
    {
        $courseName = $question->game?->unit?->course?->name;

        if (is_string($courseName) && trim($courseName) !== '') {
            return trim($courseName);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    private function buildWriteData(array $payload, array $actor, bool $isCreate): array
    {
        $data = $payload;
        unset($data['challenge_type'], $data['chapter_resolution']);

        if (($payload['challenge_type'] ?? null) === 'school' || isset($payload['chapter_resolution'])) {
            $resolution = $payload['chapter_resolution'] ?? [];
            $chapter = $this->chapterService->resolveChapter(
                $actor['actorUserId'],
                (string) ($payload['subject_id'] ?? $resolution['subject_id'] ?? ''),
                $resolution['selected_chapter_id'] ?? $payload['chapter_id'] ?? null,
                $resolution['new_chapter_name'] ?? null,
            );

            $data['chapter_id'] = $chapter['chapter_id'];
            $data['category_id'] = null;
        }

        if (($payload['challenge_type'] ?? null) === 'family') {
            $data['chapter_id'] = null;
        }

        if ($isCreate) {
            $now = now();
            $data['submitted_by'] = $actor['actorUserId'];
            $data['submitted_at'] = $now;
            $data['question_source'] = $data['question_source'] ?? 'manual';
            $data['ai_generated'] = $data['ai_generated'] ?? ($data['question_source'] === 'textbook_ai');

            if (! isset($data['approval_status'])) {
                if ($actor['actorRole'] === 'admin') {
                    $data['approval_status'] = 'approved';
                    $data['approved_by'] = $actor['actorUserId'];
                    $data['approved_at'] = $now;
                } else {
                    $data['approval_status'] = 'pending';
                }
            } elseif ($data['approval_status'] === 'approved') {
                $data['approved_by'] = $actor['actorUserId'];
                $data['approved_at'] = $now;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array{actorUserId: string, actorRole: string}  $actor
     * @return array<string, mixed>
     */
    private function updateApproval(string $questionId, array $actor, array $fields): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canEditQuestions')) {
            throw new ForbiddenException();
        }

        $existing = $this->getById($questionId);

        if (
            ! empty($existing['submitted_by'])
            && $existing['submitted_by'] === $actor['actorUserId']
            && $actor['actorRole'] !== 'admin'
        ) {
            throw new ForbiddenException('Cannot approve or reject your own question');
        }

        $fields['updated_at'] = now();
        Question::query()->where('id', $questionId)->update($fields);

        return $this->getById($questionId);
    }
}
