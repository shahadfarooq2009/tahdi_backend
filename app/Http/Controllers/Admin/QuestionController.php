<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ValidationException;
use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkQuestionRequest;
use App\Http\Requests\Admin\ChapterResolveRequest;
use App\Http\Requests\Admin\ImportQuestionsRequest;
use App\Http\Requests\Admin\StoreQuestionRequest;
use App\Http\Requests\Admin\UpdateQuestionRequest;
use App\Services\Admin\ChapterService;
use App\Services\Admin\QuestionService;
use App\Services\Audit\AuditService;
use App\Support\QuestionConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly QuestionService $questions,
        private readonly ChapterService $chapters,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->parseListFilters($request);

        return $this->success($this->questions->list($filters));
    }

    public function show(string $id): JsonResponse
    {
        return $this->success($this->questions->getById($id));
    }

    public function store(StoreQuestionRequest $request): JsonResponse
    {
        try {
            $data = $this->questions->create($request->validated(), $this->actor($request));
            $this->audit->write($request->attributes->get('auth_user')->id, 'QUESTION_CREATED', $data['id'] ?? null, true, [
                'subject_id' => $data['subject_id'] ?? null,
                'points_value' => $data['points_value'] ?? null,
            ]);

            return $this->success($data, 201);
        } catch (\Throwable $e) {
            $this->audit->write($request->attributes->get('auth_user')->id ?? null, 'QUESTION_CREATED', null, false);
            throw $e;
        }
    }

    public function update(UpdateQuestionRequest $request, string $id): JsonResponse
    {
        try {
            $data = $this->questions->update($id, $request->validated(), $this->actor($request));
            $this->audit->write($request->attributes->get('auth_user')->id, 'QUESTION_UPDATED', $id, true);

            return $this->success($data);
        } catch (\Throwable $e) {
            $this->audit->write($request->attributes->get('auth_user')->id ?? null, 'QUESTION_UPDATED', $id, false);
            throw $e;
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $permanent = $request->query('permanent') === 'true';
        $data = $permanent
            ? $this->questions->permanentlyDelete($id, $this->actor($request))
            : $this->questions->softDelete($id, $this->actor($request));

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            $permanent ? 'QUESTION_PERMANENTLY_DELETED' : 'QUESTION_DELETED',
            $id,
            true
        );

        return $this->success($data);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $data = $this->questions->restore($id, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'QUESTION_RESTORED', $id, true);

        return $this->success($data);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $data = $this->questions->approve($id, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'QUESTION_APPROVED', $id, true);

        return $this->success($data);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $reason = $request->input('rejection_reason');
        $data = $this->questions->reject($id, is_string($reason) ? trim($reason) : null, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'QUESTION_REJECTED', $id, true);

        return $this->success($data);
    }

    public function bulkDelete(BulkQuestionRequest $request): JsonResponse
    {
        $data = $this->questions->bulkSoftDelete($request->validated('ids'), $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'QUESTION_BULK_DELETED', null, true, [
            'count' => count($request->validated('ids')),
        ]);

        return $this->success($data);
    }

    public function bulkApprove(BulkQuestionRequest $request): JsonResponse
    {
        $data = $this->questions->bulkApprove($request->validated('ids'), $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'QUESTION_BULK_APPROVED', null, true, [
            'count' => count($request->validated('ids')),
        ]);

        return $this->success($data);
    }

    public function bulkReject(BulkQuestionRequest $request): JsonResponse
    {
        $reason = $request->validated('rejection_reason');
        $data = $this->questions->bulkReject($request->validated('ids'), $reason, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'QUESTION_BULK_REJECTED', null, true, [
            'count' => count($request->validated('ids')),
        ]);

        return $this->success($data);
    }

    public function import(ImportQuestionsRequest $request): JsonResponse
    {
        $data = $this->questions->import($request->importRows(), $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'QUESTION_IMPORTED', null, true, [
            'count' => count($data),
        ]);

        return $this->success($data, 201);
    }

    public function resolveChapter(ChapterResolveRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actor = $this->actor($request);

        $data = $this->chapters->resolveChapter(
            $actor['actorUserId'],
            $validated['subject_id'],
            $validated['selected_chapter_id'] ?? null,
            $validated['new_chapter_name'] ?? null,
        );

        return $this->success($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseListFilters(Request $request): array
    {
        $filters = [
            'grade' => $request->query('grade'),
            'subject_id' => $request->query('subject_id'),
            'unit' => $request->query('unit'),
            'chapter_id' => $request->query('chapter_id'),
            'points_value' => $request->query('points_value') !== null ? (int) $request->query('points_value') : null,
            'difficulty_level' => $request->query('difficulty_level') !== null ? (int) $request->query('difficulty_level') : null,
            'approval_status' => $request->query('approval_status'),
            'question_source' => $request->query('question_source'),
            'search' => $request->query('search') ? trim((string) $request->query('search')) : null,
            'is_deleted' => $request->query('is_deleted') === 'true',
            'submitted_by' => $request->query('submitted_by'),
            'has_category' => $request->query('has_category'),
            'exclude_question_sources' => $this->parseExcludeQuestionSources($request->query('exclude_question_sources')),
        ];

        if ($filters['approval_status'] && ! in_array($filters['approval_status'], QuestionConstants::APPROVAL_STATUSES, true)) {
            throw new ValidationException('Invalid approval_status filter');
        }

        if ($filters['question_source'] && ! in_array($filters['question_source'], QuestionConstants::SOURCES, true)) {
            throw new ValidationException('Invalid question_source filter');
        }

        if ($filters['points_value'] && ! in_array($filters['points_value'], QuestionConstants::POINT_VALUES, true)) {
            throw new ValidationException('Invalid points_value filter');
        }

        return $filters;
    }

    /**
     * @return string[]|null
     */
    private function parseExcludeQuestionSources(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $sources = array_values(array_filter(array_map(
            static fn (string $source) => trim($source),
            explode(',', $value),
        )));

        foreach ($sources as $source) {
            if (! in_array($source, QuestionConstants::SOURCES, true)) {
                throw new ValidationException('Invalid exclude_question_sources filter');
            }
        }

        return $sources !== [] ? $sources : null;
    }
}
