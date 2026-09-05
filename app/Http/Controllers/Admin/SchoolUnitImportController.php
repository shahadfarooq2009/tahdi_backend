<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BackfillSchoolImportSourceRequest;
use App\Http\Requests\Admin\ImportSchoolExcelRequest;
use App\Services\Admin\SchoolExcelImportService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;

class SchoolUnitImportController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly SchoolExcelImportService $importService,
        private readonly AuditService $audit,
    ) {}

    public function import(ImportSchoolExcelRequest $request): JsonResponse
    {
        $actor = $this->actor($request);

        $summary = $this->importService->import(
            $request->file('file'),
            $request->validated('subject_id'),
            $request->validated('educational_stage'),
            $request->validated('grade'),
            $actor['actorUserId'],
            $actor['actorRole'],
            $request->validated('course_id'),
        );

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            'SCHOOL_UNITS_IMPORTED',
            null,
            true,
            $summary,
        );

        return $this->success($summary, 201);
    }

    public function backfillImportSource(BackfillSchoolImportSourceRequest $request): JsonResponse
    {
        $summary = $this->importService->backfillImportSource(
            $request->validated('import_source'),
            $request->validated('subject_id'),
            $request->validated('educational_stage'),
            $request->validated('grade'),
            $request->validated('course_id'),
        );

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            'SCHOOL_IMPORT_SOURCE_BACKFILLED',
            null,
            true,
            $summary,
        );

        return $this->success($summary);
    }
}
