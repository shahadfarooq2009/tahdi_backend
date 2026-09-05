<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ValidationException;
use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSubjectRequest;
use App\Http\Requests\Admin\UpdateSubjectGradeRequest;
use App\Http\Requests\Admin\UpdateSubjectRequest;
use App\Services\Admin\SubjectService;
use App\Services\Audit\AuditService;
use App\Support\Grades;
use App\Support\QuestionConstants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly SubjectService $subjects,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->subjects->list($this->parseListFilters($request)));
    }

    public function questionStats(): JsonResponse
    {
        return $this->success($this->subjects->questionStats());
    }

    public function show(string $id): JsonResponse
    {
        return $this->success($this->subjects->getById($id));
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $data = $this->subjects->create($request->validated(), $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'SUBJECT_CREATED', $data['id'] ?? null, true);

        return $this->success($data, 201);
    }

    public function update(UpdateSubjectRequest $request, string $id): JsonResponse
    {
        $data = $this->subjects->update($id, $request->validated(), $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'SUBJECT_UPDATED', $id, true);

        return $this->success($data);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $permanent = $request->query('permanent') === 'true';
        $data = $permanent
            ? $this->subjects->permanentlyDelete($id, $this->actor($request))
            : $this->subjects->softDelete($id, $this->actor($request));

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            $permanent ? 'SUBJECT_PERMANENTLY_DELETED' : 'SUBJECT_DELETED',
            $id,
            true
        );

        return $this->success($data);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $data = $this->subjects->restore($id, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'SUBJECT_RESTORED', $id, true);

        return $this->success($data);
    }

    public function updateGrades(UpdateSubjectGradeRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->subjects->updateGradeMapping(
            $id,
            $validated['grade'],
            (bool) ($validated['remove'] ?? false),
            $this->actor($request)
        ));
    }

    public function toggleCompletion(Request $request, string $id, string $grade): JsonResponse
    {
        $normalized = Grades::normalize($grade);

        if (! Grades::isValid($normalized)) {
            throw new ValidationException('Invalid grade');
        }

        return $this->success($this->subjects->toggleCompletion($id, $normalized, $this->actor($request)));
    }

    /**
     * @return array<string, mixed>
     */
    private function parseListFilters(Request $request): array
    {
        $challengeType = $request->query('challenge_type');

        if ($challengeType && ! in_array($challengeType, QuestionConstants::CHALLENGE_TYPES, true)) {
            throw new ValidationException('Invalid challenge_type filter');
        }

        return [
            'is_deleted' => $request->query('is_deleted') === 'true',
            'challenge_type' => $challengeType,
            'search' => $request->query('search') ? trim((string) $request->query('search')) : null,
            'school_stage_scope' => $request->query('school_stage_scope')
                ? (string) $request->query('school_stage_scope')
                : null,
        ];
    }
}
