<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ValidationException;
use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkGeneratedQuestionReviewRequest;
use App\Http\Requests\Admin\GenerateQuestionsRequest;
use App\Http\Requests\Admin\GenerateUnitQuestionsRequest;
use App\Http\Requests\Admin\GeneratedQuestionReviewRequest;
use App\Http\Requests\Admin\PatchTextbookStructureRequest;
use App\Http\Requests\Admin\StoreTextbookRequest;
use App\Http\Requests\Admin\UnitMappingRequest;
use App\Http\Requests\Admin\UploadTextbookFileRequest;
use App\Services\Audit\AuditService;
use App\Services\Curriculum\ReviewSetUsageService;
use App\Services\Curriculum\StructurePatchService;
use App\Services\Curriculum\TextbookAiService;
use App\Services\Curriculum\TextbookService;
use App\Services\Curriculum\UnitGenerationOrchestratorService;
use App\Services\Curriculum\UnitMappingService;
use App\Support\TextbookProcessingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TextbookController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly TextbookService $textbooks,
        private readonly TextbookAiService $textbookAi,
        private readonly UnitGenerationOrchestratorService $unitGeneration,
        private readonly StructurePatchService $structurePatch,
        private readonly UnitMappingService $unitMappings,
        private readonly ReviewSetUsageService $reviewSets,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [];

        if ($request->filled('processing_status')) {
            $allowed = TextbookProcessingStatus::all();
            $status = (string) $request->query('processing_status');

            if (! in_array($status, $allowed, true)) {
                throw new ValidationException('Invalid processing_status filter');
            }

            $filters['processing_status'] = $status;
        }

        if ($request->filled('subject_id')) {
            $filters['subject_id'] = (string) $request->query('subject_id');
        }

        return $this->success($this->textbooks->list($filters));
    }

    public function store(StoreTextbookRequest $request): JsonResponse
    {
        try {
            $data = $this->textbooks->createUpload($request->validated(), $this->actor($request)['actorUserId']);

            $this->audit->write(
                $request->attributes->get('auth_user')->id,
                'TEXTBOOK_UPLOADED',
                $data['textbook']['id'] ?? null,
                true,
                [
                    'title' => $data['textbook']['title'] ?? null,
                    'file_size_bytes' => $data['textbook']['file_size_bytes'] ?? null,
                ],
            );

            return $this->success($data, 201);
        } catch (\Throwable $exception) {
            $this->audit->write(
                $request->attributes->get('auth_user')?->id,
                'TEXTBOOK_UPLOADED',
                null,
                false,
            );

            throw $exception;
        }
    }

    public function process(Request $request, string $id): JsonResponse
    {
        $data = $this->textbooks->startProcessing($id, $this->actor($request)['actorUserId']);

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            'TEXTBOOK_PROCESSING_STARTED',
            $id,
            true,
            ['mode' => 'stored_file'],
        );

        return $this->success($data, 202);
    }

    public function confirmUpload(Request $request, string $id): JsonResponse
    {
        return $this->process($request, $id);
    }

    public function uploadFile(UploadTextbookFileRequest $request, string $id): JsonResponse
    {
        $file = $request->file('file');

        logger()->info('Textbook file upload request received', [
            'textbook_id' => $id,
            'original_name' => $file?->getClientOriginalName(),
            'mime_type' => $file?->getMimeType(),
            'size_bytes' => $file?->getSize(),
        ]);

        try {
            $data = $this->textbooks->storeUploadedFile($id, $file, $this->actor($request)['actorUserId']);

            $this->audit->write(
                $request->attributes->get('auth_user')->id,
                'TEXTBOOK_FILE_UPLOADED',
                $id,
                true,
                [
                    'file_size_bytes' => $data['textbook']['file_size_bytes'] ?? null,
                    'storage_path' => $data['textbook']['storage_path'] ?? null,
                ],
            );

            return $this->success($data);
        } catch (\Throwable $exception) {
            logger()->error('Textbook file upload request failed', [
                'textbook_id' => $id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->audit->write(
                $request->attributes->get('auth_user')?->id,
                'TEXTBOOK_FILE_UPLOADED',
                $id,
                false,
            );

            throw $exception;
        }
    }

    public function status(string $id): JsonResponse
    {
        return $this->success($this->textbooks->status($id));
    }

    public function processingStatus(string $id): JsonResponse
    {
        return $this->success($this->textbooks->processingTimeline($id));
    }

    public function analysis(string $id): JsonResponse
    {
        return $this->success($this->textbooks->analysis($id));
    }

    public function patchStructure(PatchTextbookStructureRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $operations = $validated['operations'] ?? [];
        $proposedStructure = $validated['proposed_structure'] ?? null;

        if (is_array($proposedStructure) && $operations === []) {
            $nextStructure = $proposedStructure;
        } else {
            $current = $this->textbooks->analysis($id);
            $baseStructure = $proposedStructure ?? $current['proposed_structure'];

            if (! is_array($baseStructure)) {
                throw new ValidationException('No proposed structure available to edit');
            }

            $nextStructure = $this->structurePatch->apply($baseStructure, $operations);
        }

        $data = $this->textbooks->updateStructure($id, ['proposed_structure' => $nextStructure]);

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            'TEXTBOOK_STRUCTURE_EDITED',
            $id,
            true,
            ['operations_count' => count($operations)],
        );

        return $this->success($data);
    }

    public function approveStructure(Request $request, string $id): JsonResponse
    {
        $force = $request->boolean('force');

        $data = $this->textbooks->approveStructure(
            $id,
            $this->actor($request)['actorUserId'],
            $force,
        );

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            'TEXTBOOK_STRUCTURE_APPROVED',
            $id,
            true,
        );

        return $this->success($data);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $job = $this->textbooks->retryProcessing($id, $this->actor($request)['actorUserId']);

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            'TEXTBOOK_PROCESSING_RETRY',
            $id,
            true,
            ['job_id' => $job->id],
        );

        return $this->success($job);
    }

    public function chapterMapping(Request $request, string $id): JsonResponse
    {
        $unitKey = $request->filled('unit_key') ? (string) $request->query('unit_key') : null;

        return $this->success($this->textbooks->chapterMappingCandidates($id, $unitKey));
    }

    public function unitMappings(string $id): JsonResponse
    {
        return $this->success($this->unitMappings->listUnitMappings($id));
    }

    public function saveUnitMapping(UnitMappingRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $data = $this->unitMappings->upsertUnitMapping(
            $id,
            $validated['chapter_id'],
            $validated['unit_key'],
            $this->actor($request)['actorUserId'],
        );

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            'CURRICULUM_UNIT_MAPPED',
            $id,
            true,
            [
                'chapter_id' => $validated['chapter_id'],
                'unit_key' => $validated['unit_key'],
            ],
        );

        return $this->success($data);
    }

    public function unitReviewSets(string $id, string $unitKey): JsonResponse
    {
        return $this->success($this->reviewSets->listReviewSetsForUnit($id, $unitKey));
    }

    public function unitRemainingReviewSets(Request $request, string $id, string $unitKey): JsonResponse
    {
        $hostUserId = $request->filled('host_user_id')
            ? (string) $request->query('host_user_id')
            : $request->attributes->get('auth_user')->id;

        $className = trim((string) $request->query('class_name', ''));

        return $this->success($this->reviewSets->getRemainingReviewSetCount(
            $id,
            $unitKey,
            $hostUserId,
            $className,
        ));
    }

    public function reviewSetDetails(string $id, string $reviewSetId): JsonResponse
    {
        return $this->success($this->reviewSets->getReviewSetDetails($reviewSetId));
    }

    public function generateQuestions(GenerateQuestionsRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $validated['count'] = $validated['count'] ?? 1;
        $validated['question_type'] = $validated['question_type'] ?? 'single_answer';
        $validated['difficulty_level'] = $validated['difficulty_level'] ?? 3;

        $data = $this->textbookAi->requestQuestionGeneration($id, $validated, $this->actor($request));

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            'AI_GENERATION_REQUESTED',
            $id,
            true,
            [
                'count' => $validated['count'],
                'points_value' => $validated['points_value'],
                'question_type' => $validated['question_type'],
            ],
        );

        return $this->success($data, 202);
    }

    public function generateUnitQuestions(GenerateUnitQuestionsRequest $request, string $id): JsonResponse
    {
        $data = $this->unitGeneration->requestUnitQuestionGeneration($id, $request->validated(), $this->actor($request));

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            'AI_UNIT_GENERATION_REQUESTED',
            $id,
            true,
            [
                'unit_key' => $request->input('unit_key'),
                'auto_promote' => $request->boolean('auto_promote', true),
            ],
        );

        return $this->success($data, 202);
    }

    public function unitGenerationStatus(string $id): JsonResponse
    {
        return $this->success($this->reviewSets->listUnitGenerationStatuses($id));
    }

    public function listGeneratedQuestions(Request $request, string $id): JsonResponse
    {
        $filters = [];

        if ($request->filled('validation_status')) {
            $allowed = ['generated', 'validated', 'needs_review', 'approved', 'rejected'];

            if (! in_array((string) $request->query('validation_status'), $allowed, true)) {
                throw new ValidationException('Invalid validation_status filter');
            }

            $filters['validation_status'] = (string) $request->query('validation_status');
        }

        return $this->success($this->textbookAi->listGeneratedQuestions($id, $filters));
    }

    public function generatedQuestionProvenance(string $id, string $generatedQuestionId): JsonResponse
    {
        return $this->success($this->textbookAi->getGeneratedQuestionProvenance($id, $generatedQuestionId));
    }

    public function reviewGeneratedQuestion(GeneratedQuestionReviewRequest $request, string $id, string $generatedQuestionId): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->textbookAi->reviewGeneratedQuestion(
            $generatedQuestionId,
            $validated['decision'],
            $this->actor($request),
            $validated['chapter_id'] ?? null,
            (bool) ($validated['create_chapter'] ?? false),
        );

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            $validated['decision'] === 'approved' ? 'AI_QUESTION_APPROVED' : 'AI_QUESTION_REJECTED',
            $generatedQuestionId,
            true,
            ['textbook_id' => $id, 'chapter_id' => $validated['chapter_id'] ?? null],
        );

        return $this->success($data);
    }

    public function bulkReviewGeneratedQuestions(BulkGeneratedQuestionReviewRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->textbookAi->bulkReviewGeneratedQuestions(
            $validated['ids'],
            $validated['decision'],
            $this->actor($request),
            $validated['chapter_id'] ?? null,
            (bool) ($validated['create_chapter'] ?? false),
        );

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            $validated['decision'] === 'approved' ? 'AI_QUESTION_BULK_APPROVED' : 'AI_QUESTION_BULK_REJECTED',
            $id,
            true,
            ['count' => count($validated['ids']), 'chapter_id' => $validated['chapter_id'] ?? null],
        );

        return $this->success($data);
    }
}
