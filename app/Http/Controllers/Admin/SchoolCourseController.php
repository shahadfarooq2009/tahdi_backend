<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSchoolCourseRequest;
use App\Http\Requests\Admin\UpdateSchoolCourseRequest;
use App\Services\Admin\SchoolCourseService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SchoolCourseController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly SchoolCourseService $courses,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request, string $subjectId): JsonResponse
    {
        return $this->success($this->courses->listForParent(
            $subjectId,
            $request->query('grade') ? (string) $request->query('grade') : null,
        ));
    }

    public function store(StoreSchoolCourseRequest $request, string $subjectId): JsonResponse
    {
        $data = $this->courses->create($subjectId, $request->validated(), $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'SCHOOL_COURSE_CREATED', $data['id'] ?? null, true);

        return $this->success($data, 201);
    }

    public function update(UpdateSchoolCourseRequest $request, string $courseId): JsonResponse
    {
        $data = $this->courses->update($courseId, $request->validated(), $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'SCHOOL_COURSE_UPDATED', $courseId, true);

        return $this->success($data);
    }

    public function destroy(Request $request, string $courseId): JsonResponse
    {
        $data = $this->courses->delete($courseId, $this->actor($request));
        $this->audit->write($request->attributes->get('auth_user')->id, 'SCHOOL_COURSE_DELETED', $courseId, true);

        return $this->success($data);
    }
}
