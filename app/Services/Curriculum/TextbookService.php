<?php

namespace App\Services\Curriculum;

use App\Exceptions\NotFoundException;
use App\Exceptions\ServiceUnavailableException;
use App\Exceptions\ValidationException;
use App\Models\Textbook;
use App\Models\TextbookProcessingJob;
use App\Services\Admin\UploadService;
use App\Support\DatabaseConfigured;
use App\Support\TextbookProcessingStage;
use App\Support\TextbookProcessingStatus;
use App\Support\TextbookProductStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TextbookService
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly TextbookFileStorageService $files,
        private readonly TextExtractionService $textExtraction,
        private readonly StructureDetectionService $structureDetection,
        private readonly ChunkingService $chunking,
        private readonly TextbookJobService $jobs,
        private readonly ChapterMappingService $chapterMapping,
        private readonly TextbookPagePersistenceMapper $pagePersistenceMapper,
        private readonly TextbookProcessingTimelineService $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{textbook: array<string, mixed>, upload: array<string, mixed>}
     */
    public function createUpload(array $payload, string $actorUserId): array
    {
        $this->assertAdminConfigured();

        $config = $this->uploads->purposeConfig('textbook-pdf');

        if ($payload['file_size'] <= 0 || $payload['file_size'] > $config['max_bytes']) {
            throw new ValidationException('File exceeds maximum allowed size');
        }

        if (! in_array($payload['content_type'], $config['mime_types'], true)) {
            throw new ValidationException('Unsupported file type');
        }

        $textbook = Textbook::query()->create([
            'title' => $payload['title'],
            'academic_stage' => $payload['academic_stage'] ?? null,
            'grade' => $payload['grade'] ?? null,
            'subject_id' => $payload['subject_id'] ?? null,
            'academic_year' => $payload['academic_year'] ?? null,
            'semester' => $payload['semester'] ?? null,
            'language' => $payload['language'] ?? 'ar',
            'storage_bucket' => 'local',
            'storage_path' => '',
            'file_size_bytes' => $payload['file_size'],
            'processing_status' => TextbookProcessingStatus::UPLOADED,
            'structure_status' => 'pending',
            'created_by' => $actorUserId,
        ]);

        $storagePath = $this->files->storagePathFor($textbook);
        $textbook->update(['storage_path' => $storagePath]);

        return [
            'textbook' => $this->sanitizeForClient($textbook->fresh()),
            'upload' => [
                'mode' => 'api',
                'url' => '/api/admin/textbooks/'.$textbook->id.'/upload',
                'bucket' => 'local',
                'path' => $storagePath,
            ],
        ];
    }

    /**
     * @return array{textbook: array<string, mixed>, jobs?: array<int, array<string, mixed>>, processing?: array<string, mixed>}
     */
    public function storeUploadedFile(string $textbookId, UploadedFile $file, string $actorUserId): array
    {
        $textbook = $this->getOrFail($textbookId);
        $targetPath = $this->files->storagePathFor($textbook);

        logger()->info('Textbook PDF upload: storing file', [
            'textbook_id' => $textbookId,
            'disk' => 'local',
            'target_path' => $targetPath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);

        try {
            $this->files->store($textbook, $file);
        } catch (\Throwable $exception) {
            logger()->error('Textbook PDF upload: storage failed', [
                'textbook_id' => $textbookId,
                'target_path' => $targetPath,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $textbook = $textbook->fresh();
        $this->files->assertStored($textbook);

        logger()->info('Textbook PDF upload: file stored successfully', [
            'textbook_id' => $textbookId,
            'storage_path' => $textbook->storage_path,
            'file_size_bytes' => $textbook->file_size_bytes,
        ]);

        try {
            $textbook->update([
                'processing_status' => TextbookProcessingStatus::UPLOADED,
                'last_error' => null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            logger()->error('Textbook PDF upload: database update failed after storage', [
                'textbook_id' => $textbookId,
                'storage_path' => $textbook->storage_path,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        logger()->info('Textbook PDF upload: record updated', [
            'textbook_id' => $textbookId,
            'processing_status' => TextbookProcessingStatus::UPLOADED,
            'storage_path' => $textbook->storage_path,
        ]);

        logger()->info('Textbook PDF upload: dispatching extract_text job', [
            'textbook_id' => $textbookId,
        ]);

        $this->jobs->enqueue($textbookId, 'extract_text', [], $actorUserId);

        $this->timeline->advanceToStage($textbookId, TextbookProcessingStage::UPLOAD, [
            'upload_completed_at' => now()->toIso8601String(),
        ]);
        $this->timeline->advanceToStage($textbookId, TextbookProcessingStage::SAVE, [
            'save_completed_at' => now()->toIso8601String(),
        ]);
        $this->timeline->initializeAfterSave($textbookId, null);

        return [
            'textbook' => $this->sanitizeForClient($textbook->fresh()),
        ];
    }

    /**
     * Queue extract_text from the persisted PDF (idempotent).
     *
     * @return array{textbook: array<string, mixed>, jobs: array<int, array<string, mixed>>, processing: array<string, mixed>}
     */
    public function queueExtractTextPipeline(string $textbookId, string $actorUserId, bool $resetArtifacts = false): array
    {
        $textbook = $this->getOrFail($textbookId);
        $this->files->assertStored($textbook);

        $status = TextbookProcessingStatus::normalize($textbook->processing_status);

        if (TextbookProcessingStatus::isActive($status)) {
            $queuedPipelineJob = TextbookProcessingJob::query()
                ->where('textbook_id', $textbookId)
                ->where('status', 'queued')
                ->whereIn('job_type', ['extract_text', 'detect_structure', 'build_chunks'])
                ->exists();

            if ($status === TextbookProcessingStatus::QUEUED && ! $queuedPipelineJob) {
                Textbook::query()
                    ->where('id', $textbookId)
                    ->update([
                        'processing_status' => TextbookProcessingStatus::UPLOADED,
                        'updated_at' => now(),
                    ]);
            } elseif ($queuedPipelineJob || $status === TextbookProcessingStatus::QUEUED) {
                $this->jobs->recoverStuckQueue($textbookId);

                return $this->status($textbookId);
            } else {
                throw new ValidationException('Textbook processing is already in progress');
            }
        }

        if (in_array($status, [
            TextbookProcessingStatus::AWAITING_UNIT_REVIEW,
            TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED,
            TextbookProcessingStatus::UNITS_APPROVED,
            TextbookProcessingStatus::GENERATING_QUESTIONS,
            TextbookProcessingStatus::AWAITING_QUESTION_REVIEW,
            TextbookProcessingStatus::READY,
        ], true)) {
            throw new ValidationException('Textbook has already completed unit detection');
        }

        if ($resetArtifacts || $status === TextbookProcessingStatus::FAILED) {
            $this->resetProcessingArtifacts($textbookId);

            Textbook::query()
                ->where('id', $textbookId)
                ->update([
                    'processing_status' => TextbookProcessingStatus::UPLOADED,
                    'structure_status' => 'pending',
                    'proposed_structure' => null,
                    'approved_structure' => null,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
        } else {
            Textbook::query()
                ->where('id', $textbookId)
                ->update([
                    'processing_status' => TextbookProcessingStatus::UPLOADED,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
        }

        logger()->info('Textbook processing: dispatching extract_text job', [
            'textbook_id' => $textbookId,
            'storage_path' => $textbook->storage_path,
            'previous_status' => $status,
        ]);

        $this->jobs->enqueue($textbookId, 'extract_text', [], $actorUserId);

        return $this->status($textbookId);
    }

    /**
     * Start (or restart) processing from the persisted backend PDF.
     *
     * @return array{textbook: array<string, mixed>, jobs: array<int, array<string, mixed>>}
     */
    public function startProcessing(string $textbookId, string $actorUserId): array
    {
        $textbook = $this->getOrFail($textbookId);
        $status = TextbookProcessingStatus::normalize($textbook->processing_status);

        if (in_array($status, [
            TextbookProcessingStatus::AWAITING_UNIT_REVIEW,
            TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED,
        ], true)) {
            throw new ValidationException('Textbook is awaiting unit review before question generation');
        }

        return $this->queueExtractTextPipeline(
            $textbookId,
            $actorUserId,
            resetArtifacts: $status === TextbookProcessingStatus::FAILED,
        );
    }

    /**
     * @return array{textbook: array<string, mixed>, jobs: array<int, array<string, mixed>>}
     */
    public function confirmUpload(string $textbookId, string $actorUserId): array
    {
        return $this->startProcessing($textbookId, $actorUserId);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $this->assertAdminConfigured();

        $query = Textbook::query()->orderByDesc('created_at');

        if (! empty($filters['processing_status'])) {
            $query->where('processing_status', $filters['processing_status']);
        }

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        return $query->get()
            ->map(fn (Textbook $textbook) => $this->sanitizeForClient($textbook))
            ->all();
    }

    /**
     * @return array{textbook: array<string, mixed>, jobs: array<int, array<string, mixed>>}
     */
    public function status(string $textbookId): array
    {
        $textbook = $this->getForStatusPoll($textbookId);
        $meta = is_array($textbook->processing_stage_meta) ? $textbook->processing_stage_meta : [];
        $pagesProcessed = (int) ($meta['processed_pages'] ?? 0);

        return [
            'textbook' => $this->sanitizeForClient($textbook, lightweight: true),
            'workflow' => $this->buildWorkflowPayload($textbook, $pagesProcessed, $meta),
        ];
    }

    /**
     * Live processing timeline for the admin UI (read-only DB state).
     *
     * @return array<string, mixed>
     */
    public function processingTimeline(string $textbookId): array
    {
        return $this->timeline->build($textbookId);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function buildWorkflowPayload(Textbook $textbook, int $pagesProcessed, array $meta = []): array
    {
        $productStatus = TextbookProductStatus::fromInternal($textbook->processing_status);
        $progressPercent = isset($meta['progress_percent']) && is_numeric($meta['progress_percent'])
            ? (int) $meta['progress_percent']
            : null;

        if ($progressPercent === null && $productStatus === TextbookProductStatus::ANALYZING) {
            $totalPages = (int) ($meta['total_pages'] ?? 0);

            if ($totalPages > 0 && $pagesProcessed > 0) {
                $progressPercent = (int) min(99, round(($pagesProcessed / $totalPages) * 100));
            } elseif ($pagesProcessed > 0) {
                $totalPages = (int) DB::table('textbook_pages')
                    ->where('textbook_id', $textbook->id)
                    ->max('page_number');

                if ($totalPages > 0) {
                    $progressPercent = (int) min(99, round(($pagesProcessed / $totalPages) * 100));
                }
            }
        }

        if ($productStatus === TextbookProductStatus::GENERATING_QUESTIONS) {
            $targetTotal = (int) DB::table('curriculum_unit_generation_status')
                ->where('textbook_id', $textbook->id)
                ->sum('target_questions');
            $generatedTotal = (int) DB::table('curriculum_unit_generation_status')
                ->where('textbook_id', $textbook->id)
                ->sum('generated_count');

            if ($targetTotal > 0) {
                $progressPercent = (int) min(99, round(($generatedTotal / $targetTotal) * 100));
            }
        }

        return [
            'status' => $productStatus,
            'message' => (string) ($meta['stage_message'] ?? TextbookProductStatus::message($productStatus, $textbook->last_error)),
            'progress_percent' => $progressPercent,
            'is_active' => TextbookProductStatus::isActive($productStatus),
            'processed_pages' => $pagesProcessed > 0 ? $pagesProcessed : null,
            'total_pages' => isset($meta['total_pages']) ? (int) $meta['total_pages'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function analysis(string $textbookId): array
    {
        $textbook = $this->getOrFail($textbookId);

        return [
            'textbook' => $this->sanitizeForClient($textbook),
            'proposed_structure' => $textbook->proposed_structure,
            'approved_structure' => $textbook->approved_structure,
            'structure_status' => $textbook->structure_status,
            'workflow_status' => TextbookProductStatus::fromInternal($textbook->processing_status),
            'workflow_message' => TextbookProductStatus::message(
                TextbookProductStatus::fromInternal($textbook->processing_status),
                $textbook->last_error
            ),
            'requires_manual_structure' => TextbookProcessingStatus::normalize($textbook->processing_status)
                === TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED,
            'last_error' => $textbook->last_error,
        ];
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function updateStructure(string $textbookId, array $patch): array
    {
        $textbook = $this->getOrFail($textbookId);

        if (! $textbook->proposed_structure && empty($patch['proposed_structure'])) {
            throw new ValidationException('No proposed structure available to edit');
        }

        $nextStructure = $patch['proposed_structure'] ?? $textbook->proposed_structure;

        $textbook->update([
            'proposed_structure' => $nextStructure,
            'updated_at' => now(),
        ]);

        return $this->sanitizeForClient($textbook->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function approveStructure(string $textbookId, string $actorUserId, bool $force = false): array
    {
        $textbook = $this->getOrFail($textbookId);

        if (! $textbook->proposed_structure) {
            throw new ValidationException('No proposed structure to approve');
        }

        $status = TextbookProcessingStatus::normalize($textbook->processing_status);

        if (! in_array($status, [
            TextbookProcessingStatus::AWAITING_UNIT_REVIEW,
            TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED,
        ], true)) {
            throw new ValidationException('Textbook is not awaiting unit review');
        }

        $structureUnits = array_values(array_filter(
            $textbook->proposed_structure['children'] ?? [],
            fn ($child) => is_array($child) && ($child['type'] ?? null) === 'unit'
        ));

        if ($structureUnits === []) {
            throw new ValidationException('Add at least one unit before approving the structure.');
        }

        $meta = $textbook->proposed_structure['_meta'] ?? [];
        $coverage = is_array($meta['coverage'] ?? null) ? $meta['coverage'] : [];

        if (
            $status !== TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED
            && ! $force
            && ($coverage['complete'] ?? false) !== true
        ) {
            throw new ValidationException(
                'Structure coverage is incomplete. All detected units, lessons, and pages must be represented before approval.',
                [
                    'missing_units' => $coverage['missing_units'] ?? [],
                    'missing_lessons' => $coverage['missing_lessons'] ?? [],
                    'uncovered_pages' => $coverage['uncovered_pages'] ?? [],
                    'coverage_percent' => $coverage['coverage_percent'] ?? 0,
                ]
            );
        }

        $textbook->update([
            'approved_structure' => $textbook->proposed_structure,
            'structure_status' => 'approved',
            'processing_status' => TextbookProcessingStatus::GENERATING_QUESTIONS,
            'last_error' => null,
            'updated_at' => now(),
        ]);

        $this->jobs->enqueue($textbookId, 'build_chunks', [], $actorUserId);

        return $this->sanitizeForClient($textbook->fresh());
    }

    public function retryProcessing(string $textbookId, string $actorUserId): TextbookProcessingJob
    {
        $textbook = $this->getOrFail($textbookId);
        $status = TextbookProcessingStatus::normalize($textbook->processing_status);

        if ($status === TextbookProcessingStatus::FAILED) {
            $failed = TextbookProcessingJob::query()
                ->where('textbook_id', $textbookId)
                ->where('status', 'failed')
                ->orderByDesc('created_at')
                ->first();

            if ($failed) {
                return $this->jobs->retryFailedForTextbook($textbookId);
            }

            $this->queueExtractTextPipeline($textbookId, $actorUserId, resetArtifacts: true);

            return TextbookProcessingJob::query()
                ->where('textbook_id', $textbookId)
                ->where('job_type', 'extract_text')
                ->orderByDesc('created_at')
                ->firstOrFail();
        }

        if (in_array($status, [TextbookProcessingStatus::UPLOADED, TextbookProcessingStatus::QUEUED], true)) {
            $this->queueExtractTextPipeline($textbookId, $actorUserId, resetArtifacts: false);

            return TextbookProcessingJob::query()
                ->where('textbook_id', $textbookId)
                ->where('job_type', 'extract_text')
                ->where('status', 'queued')
                ->orderByDesc('created_at')
                ->firstOrFail();
        }

        return $this->jobs->retryFailedForTextbook($textbookId);
    }

    /**
     * Re-run PDF extraction + structure detection from the stored backend PDF.
     * Clears extracted pages/structure so OCR and improved extraction can apply.
     *
     * @return array{textbook: array<string, mixed>, jobs: array<int, array<string, mixed>>, processing: array<string, mixed>}
     */
    public function reprocessExtractionFromStoredPdf(string $textbookId, string $actorUserId): array
    {
        $textbook = $this->getOrFail($textbookId);
        $this->files->assertStored($textbook);

        $this->resetProcessingArtifacts($textbookId);

        Textbook::query()
            ->where('id', $textbookId)
            ->update([
                'processing_status' => TextbookProcessingStatus::UPLOADED,
                'structure_status' => 'pending',
                'proposed_structure' => null,
                'approved_structure' => null,
                'extraction_diagnostics' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        logger()->info('Textbook extraction reprocess requested', [
            'textbook_id' => $textbookId,
            'actor_user_id' => $actorUserId,
            'storage_path' => $textbook->storage_path,
        ]);

        $this->jobs->enqueue($textbookId, 'extract_text', [], $actorUserId);

        return $this->status($textbookId);
    }

    public function markGeneratingQuestions(string $textbookId): void
    {
        Textbook::query()
            ->where('id', $textbookId)
            ->update([
                'processing_status' => TextbookProcessingStatus::GENERATING_QUESTIONS,
                'updated_at' => now(),
            ]);
    }

    public function markAwaitingQuestionReview(string $textbookId): void
    {
        Textbook::query()
            ->where('id', $textbookId)
            ->update([
                'processing_status' => TextbookProcessingStatus::READY,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function chapterMappingCandidates(string $textbookId, ?string $unitKey): array
    {
        $textbook = $this->getOrFail($textbookId);
        $unitTitle = $this->findNodeTitle(
            $textbook->approved_structure ?? $textbook->proposed_structure,
            $unitKey
        ) ?? 'وحدة الكتاب';

        if (! $textbook->subject_id) {
            return [
                'unit_title' => $unitTitle,
                'unit_key' => $unitKey,
                'subject_id' => null,
                'match' => null,
                'candidates' => [],
            ];
        }

        $match = $this->chapterMapping->findChapterForUnitTitle($textbook->subject_id, $unitTitle);

        return [
            'unit_title' => $unitTitle,
            'unit_key' => $unitKey,
            'subject_id' => $textbook->subject_id,
            'match' => $match['chapter_id']
                ? ['chapter_id' => $match['chapter_id'], 'match_type' => $match['match_type']]
                : null,
            'candidates' => $match['candidates'],
            'ambiguous_matches' => $match['ambiguous_matches'] ?? [],
        ];
    }

    public function runExtractText(TextbookProcessingJob $job): void
    {
        $shouldEnqueueDetect = false;
        $pipelineStartedAt = microtime(true);

        $setup = DB::transaction(function () use ($job): ?array {
            $textbook = Textbook::query()->find($job->textbook_id);

            if (! $textbook) {
                throw new NotFoundException('Textbook not found');
            }

            $this->files->assertStored($textbook);

            $duplicateProcessing = TextbookProcessingJob::query()
                ->where('textbook_id', $textbook->id)
                ->where('job_type', 'extract_text')
                ->where('status', 'processing')
                ->where('id', '!=', $job->id)
                ->exists();

            if ($duplicateProcessing) {
                logger()->warning('Skipping duplicate extract_text job', [
                    'textbook_id' => $textbook->id,
                    'job_id' => $job->id,
                ]);

                return null;
            }

            $existingPageCount = (int) DB::table('textbook_pages')
                ->where('textbook_id', $textbook->id)
                ->count();

            if ($existingPageCount > 0) {
                $this->jobs->updateProgress($job->id, 60);
                $this->timeline->advanceToStage($textbook->id, TextbookProcessingStage::EXTRACT_TEXT, [
                    'phase' => 'extracting',
                    'processed_pages' => $existingPageCount,
                    'total_pages' => $existingPageCount,
                    'progress_percent' => 100,
                    'stage_message' => 'تم استخراج محتوى الكتاب',
                    'extract_completed_at' => now()->toIso8601String(),
                ], true);
                $textbook->update([
                    'processing_status' => TextbookProcessingStatus::ANALYZING_CONTENTS,
                    'updated_at' => now(),
                ]);

                return ['skip' => true, 'textbook_id' => $textbook->id];
            }

            $textbook->update([
                'processing_status' => TextbookProcessingStatus::EXTRACTING,
                'last_error' => null,
                'updated_at' => now(),
            ]);

            DB::table('textbook_pages')->where('textbook_id', $textbook->id)->delete();

            return ['skip' => false, 'textbook_id' => $textbook->id];
        });

        if ($setup === null) {
            return;
        }

        if ($setup['skip'] ?? false) {
            $this->jobs->enqueue($job->textbook_id, 'detect_structure', [], $job->created_by);

            return;
        }

        $textbookId = (string) $setup['textbook_id'];
        $textbook = $this->getOrFail($textbookId);
        $absolutePath = $this->files->absolutePath($textbook);
        $fileSizeBytes = is_file($absolutePath) ? (int) filesize($absolutePath) : (int) ($textbook->file_size_bytes ?? 0);
        $expectedPageCount = app(PdfExternalTools::class)->pdfPageCount($absolutePath);

        $this->timeline->updateExtractionProgress($textbookId, [
            'phase' => 'extracting',
            'processed_pages' => 0,
            'total_pages' => $expectedPageCount,
            'stage_message' => 'جاري استخراج محتوى الكتاب',
        ], true);

        logger()->info('Starting PDF extraction', [
            'textbook_id' => $textbookId,
            'job_id' => $job->id,
            'file_path' => $absolutePath,
            'file_size_bytes' => $fileSizeBytes,
        ]);

        $extractionStartedAt = microtime(true);

        $extraction = $this->textExtraction->extractPdfPagesFromPathWithDiagnostics(
            $absolutePath,
            function (int $pageNumber, int $pageTotal) use ($job, $textbookId): void {
                $progress = (int) round(($pageNumber / max(1, $pageTotal)) * 55);
                $this->jobs->updateProgress($job->id, $progress);
                $this->timeline->updateExtractionProgress($textbookId, [
                    'phase' => 'extracting',
                    'processed_pages' => $pageNumber,
                    'total_pages' => $pageTotal,
                    'stage_message' => 'جاري استخراج محتوى الكتاب',
                ]);
            },
            function (int $ocrDone, int $ocrTotal) use ($textbookId): void {
                if ($ocrDone === 1) {
                    $this->timeline->advanceToStage($textbookId, TextbookProcessingStage::OCR_ENHANCE, [
                        'phase' => 'ocr',
                        'ocr_triggered' => true,
                        'ocr_processed' => $ocrDone,
                        'ocr_total' => $ocrTotal,
                        'extract_completed_at' => now()->toIso8601String(),
                        'stage_message' => 'جاري تحسين قراءة الصفحات',
                    ], true);
                }

                $this->timeline->updateExtractionProgress($textbookId, [
                    'phase' => 'ocr',
                    'ocr_processed' => $ocrDone,
                    'ocr_total' => $ocrTotal,
                    'processed_pages' => $ocrDone,
                    'total_pages' => $ocrTotal,
                    'stage_message' => 'جاري تحسين قراءة الصفحات',
                ], true);
            }
        );

        $pages = $extraction['pages'];
        $insertRows = $this->pagePersistenceMapper->mapForInsert($pages, $textbookId);
        $persistStartedAt = microtime(true);

        DB::transaction(function () use ($textbookId, $insertRows, $extraction, $pages, $job, $pipelineStartedAt, $extractionStartedAt, $persistStartedAt, $fileSizeBytes, $absolutePath): void {
            $textbook = Textbook::query()->find($textbookId);

            if (! $textbook) {
                throw new NotFoundException('Textbook not found');
            }

            foreach (array_chunk($insertRows, 100) as $chunk) {
                DB::table('textbook_pages')->insert($chunk);
            }

            $totalChars = array_sum(array_map(
                fn (array $page) => mb_strlen($page['content_text'] ?? ''),
                $pages
            ));

            $diagnostics = is_array($extraction['diagnostics'] ?? null) ? $extraction['diagnostics'] : [];
            $diagnostics['pipeline_metrics'] = [
                'extraction_elapsed_ms' => (int) round((microtime(true) - $extractionStartedAt) * 1000),
                'db_persist_elapsed_ms' => (int) round((microtime(true) - $persistStartedAt) * 1000),
                'total_elapsed_ms' => (int) round((microtime(true) - $pipelineStartedAt) * 1000),
                'file_size_bytes' => $fileSizeBytes,
            ];

            logger()->info('PDF extraction completed', [
                'textbook_id' => $textbookId,
                'job_id' => $job->id,
                'page_count' => count($pages),
                'extracted_character_count' => $totalChars,
                'extraction_diagnostics' => $diagnostics,
                'elapsed_ms' => (int) round((microtime(true) - $pipelineStartedAt) * 1000),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ]);

            $this->jobs->updateProgress($job->id, 60);

            $this->timeline->advanceToStage($textbookId, TextbookProcessingStage::EXTRACT_TEXT, [
                'phase' => 'extracting',
                'processed_pages' => count($pages),
                'total_pages' => count($pages),
                'progress_percent' => 100,
                'stage_message' => 'تم استخراج محتوى الكتاب',
                'extract_completed_at' => now()->toIso8601String(),
            ], true);

            $textbook->update([
                'processing_status' => TextbookProcessingStatus::ANALYZING_CONTENTS,
                'extraction_diagnostics' => $diagnostics,
                'updated_at' => now(),
            ]);
        });

        $this->jobs->enqueue($job->textbook_id, 'detect_structure', [], $job->created_by);
    }

    /**
     * After a pipeline stage completes, ensure the next stage is queued so the
     * textbook cannot remain logically incomplete while the Laravel job shows DONE.
     */
    public function ensurePipelineContinuity(TextbookProcessingJob $completedJob): void
    {
        match ($completedJob->job_type) {
            'extract_text' => $this->ensureDetectStructureQueued($completedJob),
            'detect_structure' => $this->ensureAwaitingUnitReview($completedJob),
            'build_chunks' => $this->ensureUnitQuestionGenerationQueued($completedJob),
            default => null,
        };
    }

    private function ensureUnitQuestionGenerationQueued(TextbookProcessingJob $buildJob): void
    {
        $hasGenerationJob = TextbookProcessingJob::query()
            ->where('textbook_id', $buildJob->textbook_id)
            ->where('job_type', 'generate_unit_questions')
            ->whereIn('status', ['queued', 'processing', 'completed'])
            ->exists();

        if ($hasGenerationJob) {
            return;
        }

        $textbook = Textbook::query()->find($buildJob->textbook_id);

        if (! $textbook || $textbook->structure_status !== 'approved') {
            return;
        }

        logger()->info('Auto-enqueueing generate_unit_questions after build_chunks', [
            'textbook_id' => $buildJob->textbook_id,
            'build_job_id' => $buildJob->id,
        ]);

        app(UnitGenerationOrchestratorService::class)->enqueueAllUnitsPipeline(
            $buildJob->textbook_id,
            (string) $buildJob->created_by,
        );
    }

    private function ensureDetectStructureQueued(TextbookProcessingJob $extractJob): void
    {
        $hasDetectPipelineJob = TextbookProcessingJob::query()
            ->where('textbook_id', $extractJob->textbook_id)
            ->where('job_type', 'detect_structure')
            ->whereIn('status', ['queued', 'processing', 'completed'])
            ->exists();

        if ($hasDetectPipelineJob) {
            return;
        }

        $pageCount = (int) DB::table('textbook_pages')
            ->where('textbook_id', $extractJob->textbook_id)
            ->count();

        if ($pageCount <= 0) {
            logger()->error('extract_text completed without pages — cannot queue detect_structure', [
                'textbook_id' => $extractJob->textbook_id,
                'processing_job_id' => $extractJob->id,
            ]);

            return;
        }

        logger()->warning('detect_structure missing after extract_text — auto-enqueueing', [
            'textbook_id' => $extractJob->textbook_id,
            'extract_job_id' => $extractJob->id,
        ]);

        Textbook::query()
            ->where('id', $extractJob->textbook_id)
            ->update([
                'processing_status' => TextbookProcessingStatus::ANALYZING_CONTENTS,
                'updated_at' => now(),
            ]);

        $this->jobs->enqueue($extractJob->textbook_id, 'detect_structure', [], $extractJob->created_by);
    }

    private function ensureAwaitingUnitReview(TextbookProcessingJob $detectJob): void
    {
        $textbook = Textbook::query()->find($detectJob->textbook_id);

        if (! $textbook) {
            return;
        }

        $status = TextbookProcessingStatus::normalize($textbook->processing_status);

        if (in_array($status, [
            TextbookProcessingStatus::AWAITING_UNIT_REVIEW,
            TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED,
            TextbookProcessingStatus::UNITS_APPROVED,
            TextbookProcessingStatus::GENERATING_QUESTIONS,
            TextbookProcessingStatus::AWAITING_QUESTION_REVIEW,
            TextbookProcessingStatus::READY,
        ], true)) {
            return;
        }

        if (! $textbook->proposed_structure) {
            logger()->error('detect_structure completed without proposed_structure', [
                'textbook_id' => $textbook->id,
                'processing_job_id' => $detectJob->id,
                'processing_status' => $status,
            ]);

            return;
        }

        logger()->warning('Textbook not awaiting_unit_review after detect_structure — reconciling', [
            'textbook_id' => $textbook->id,
            'processing_status' => $status,
        ]);

        $textbook->update([
            'structure_status' => $textbook->structure_status === 'approved' ? 'approved' : 'review_required',
            'processing_status' => TextbookProcessingStatus::AWAITING_UNIT_REVIEW,
            'updated_at' => now(),
        ]);
    }

    public function runDetectStructure(TextbookProcessingJob $job): void
    {
        $startedAt = microtime(true);
        $textbook = $this->getOrFail($job->textbook_id);

        logger()->info('Starting structure detection', [
            'textbook_id' => $textbook->id,
            'job_id' => $job->id,
        ]);

        $this->timeline->advanceToStage($textbook->id, TextbookProcessingStage::DETECT_TOC, [
            'stage_message' => 'جاري تحليل الفهرس',
        ], true);

        Textbook::query()
            ->where('id', $textbook->id)
            ->update([
                'processing_status' => TextbookProcessingStatus::ANALYZING_CONTENTS,
                'updated_at' => now(),
            ]);

        $pages = DB::table('textbook_pages')
            ->where('textbook_id', $textbook->id)
            ->orderBy('page_number')
            ->get(['page_number', 'content_text'])
            ->map(fn ($row) => [
                'page_number' => (int) $row->page_number,
                'content_text' => (string) $row->content_text,
            ])
            ->all();

        $this->timeline->advanceToStage($textbook->id, TextbookProcessingStage::DETECT_UNITS, [
            'stage_message' => 'جاري اكتشاف الوحدات',
        ], true);

        Textbook::query()
            ->where('id', $textbook->id)
            ->update([
                'processing_status' => TextbookProcessingStatus::UNITS_DETECTED,
                'updated_at' => now(),
            ]);

        $detectionStartedAt = microtime(true);
        $detection = $this->structureDetection->detectTextbookStructure($pages, $textbook->title);
        $candidates = app(ArabicStructureDetector::class)->detectCandidates($pages);
        $outcome = $this->structureDetection->evaluateAutomaticDetection(
            $detection,
            $candidates,
            count($pages)
        );

        $unitCount = count($detection['structure']['children'] ?? []);

        $this->timeline->advanceToStage($textbook->id, TextbookProcessingStage::PREPARE_REVIEW, [
            'units_detected' => $unitCount,
            'stage_message' => 'جاري تجهيز الوحدات للمراجعة',
        ], true);

        DB::transaction(function () use ($textbook, $job, $startedAt, $detectionStartedAt, $pages, $detection, $outcome, $unitCount): void {
            $locked = Textbook::query()->find($textbook->id);

            if (! $locked) {
                throw new NotFoundException('Textbook not found');
            }

            logger()->info('Structure detection completed', [
                'textbook_id' => $locked->id,
                'job_id' => $job->id,
                'unit_count' => $unitCount,
                'detection_success' => $outcome['success'],
                'detection_reason' => $outcome['reason'],
                'structure_detection_elapsed_ms' => (int) round((microtime(true) - $detectionStartedAt) * 1000),
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            if (! $outcome['success']) {
                $locked->update([
                    'proposed_structure' => $this->structureDetection->buildManualStructureShell(
                        $locked->title,
                        count($pages),
                        [
                            'used_ai' => $detection['used_ai'],
                            'detection_mode' => $detection['detection_mode'],
                            'coverage' => $detection['coverage'],
                            'merge_actions' => $detection['merge_actions'],
                            'reason' => $outcome['reason'],
                        ]
                    ),
                    'structure_status' => 'review_required',
                    'processing_status' => TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED,
                    'last_error' => $outcome['message'],
                    'updated_at' => now(),
                ]);

                $this->timeline->advanceToStage($locked->id, TextbookProcessingStage::UNIT_REVIEW, [
                    'units_detected' => 0,
                    'manual_structure' => true,
                    'stage_message' => 'الوحدات جاهزة للمراجعة',
                ], true);

                return;
            }

            $locked->update([
                'proposed_structure' => $detection['structure'],
                'structure_status' => 'review_required',
                'processing_status' => TextbookProcessingStatus::AWAITING_UNIT_REVIEW,
                'last_error' => ($detection['coverage']['complete'] ?? false)
                    ? null
                    : 'Structure coverage incomplete: '.json_encode([
                        'missing_units' => $detection['coverage']['missing_units'] ?? [],
                        'missing_lessons' => $detection['coverage']['missing_lessons'] ?? [],
                        'uncovered_pages' => $detection['coverage']['uncovered_pages'] ?? [],
                    ], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            $this->timeline->advanceToStage($locked->id, TextbookProcessingStage::UNIT_REVIEW, [
                'units_detected' => $unitCount,
                'stage_message' => "تم اكتشاف {$unitCount} وحدات",
            ], true);
        });
    }

    public function runBuildChunks(TextbookProcessingJob $job): void
    {
        $textbook = $this->getOrFail($job->textbook_id);
        $structure = $textbook->approved_structure;

        if (! is_array($structure)) {
            throw new ValidationException('Approved structure is required to build chunks');
        }

        $textbook->update([
            'processing_status' => TextbookProcessingStatus::GENERATING_QUESTIONS,
            'updated_at' => now(),
        ]);

        $pages = DB::table('textbook_pages')
            ->where('textbook_id', $textbook->id)
            ->orderBy('page_number')
            ->get(['page_number', 'content_text', 'normalized_text'])
            ->map(fn ($row) => [
                'page_number' => (int) $row->page_number,
                'content_text' => (string) $row->content_text,
                'normalized_text' => (string) ($row->normalized_text ?: ArabicTextService::normalizeArabicText((string) $row->content_text)),
            ])
            ->all();

        DB::table('textbook_content_chunks')->where('textbook_id', $textbook->id)->delete();

        $chunks = $this->chunking->buildChunksFromStructure($pages, $structure);

        if ($chunks !== []) {
            DB::table('textbook_content_chunks')->insert(array_map(fn (array $chunk) => [
                'id' => (string) Str::uuid(),
                'textbook_id' => $textbook->id,
                'unit_key' => $chunk['unit_key'],
                'lesson_key' => $chunk['lesson_key'],
                'section_key' => $chunk['section_key'],
                'unit_title' => $chunk['unit_title'],
                'lesson_title' => $chunk['lesson_title'],
                'section_title' => $chunk['section_title'],
                'source_page_start' => $chunk['source_page_start'],
                'source_page_end' => $chunk['source_page_end'],
                'content' => $chunk['content'],
                'token_estimate' => $chunk['token_estimate'],
                'metadata' => json_encode([
                    ...($chunk['metadata'] ?? []),
                    'normalized_content' => $chunk['normalized_content'] ?? null,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ], $chunks));
        }

        $textbook->update([
            'processing_status' => TextbookProcessingStatus::GENERATING_QUESTIONS,
            'updated_at' => now(),
        ]);
    }

    public function getOrFail(string $textbookId): Textbook
    {
        $this->assertAdminConfigured();

        $textbook = Textbook::query()->find($textbookId);

        if (! $textbook) {
            throw new NotFoundException('Textbook not found');
        }

        return $textbook;
    }

    public function getForStatusPoll(string $textbookId): Textbook
    {
        $this->assertAdminConfigured();

        $textbook = Textbook::query()
            ->select([
                'id',
                'title',
                'academic_stage',
                'grade',
                'subject_id',
                'academic_year',
                'semester',
                'language',
                'storage_bucket',
                'storage_path',
                'file_size_bytes',
                'processing_status',
                'processing_current_stage',
                'processing_stage_meta',
                'structure_status',
                'created_by',
                'created_at',
                'updated_at',
                'last_error',
            ])
            ->find($textbookId);

        if (! $textbook) {
            throw new NotFoundException('Textbook not found');
        }

        return $textbook;
    }

    private function resetProcessingArtifacts(string $textbookId): void
    {
        DB::table('textbook_pages')->where('textbook_id', $textbookId)->delete();
        DB::table('textbook_content_chunks')->where('textbook_id', $textbookId)->delete();
        DB::table('curriculum_unit_generation_status')->where('textbook_id', $textbookId)->delete();
    }

    /**
     * @param  array<string, mixed>|null  $structure
     */
    private function findNodeTitle(?array $structure, ?string $key): ?string
    {
        if (! $structure || ! $key) {
            return null;
        }

        $title = null;

        $this->chunking->walkStructure($structure, function (array $node) use ($key, &$title): void {
            if (($node['key'] ?? null) === $key) {
                $title = $node['title'] ?? null;
            }
        });

        return is_string($title) ? $title : null;
    }

    /**
     * @param  array<int, TextbookProcessingJob>  $jobs
     * @return array<string, mixed>
     */
    private function buildProcessingDiagnostics(Textbook $textbook, array $jobs, int $pagesProcessed): array
    {
        $status = TextbookProcessingStatus::normalize($textbook->processing_status);
        $activeJob = collect($jobs)->first(
            fn (TextbookProcessingJob $job) => in_array($job->status, ['queued', 'processing'], true)
        );

        $totalPages = null;

        if ($activeJob?->job_type === 'extract_text' && $activeJob->progress > 0) {
            $totalPages = (int) round($pagesProcessed / max(1, $activeJob->progress) * 100);
        }

        if ($pagesProcessed > 0 && $totalPages !== null && $totalPages < $pagesProcessed) {
            $totalPages = $pagesProcessed;
        }

        return [
            'status' => $status,
            'stage' => $status,
            'message' => $this->processingStageMessage($status, $activeJob, $jobs),
            'pages_processed' => $pagesProcessed > 0 ? $pagesProcessed : null,
            'total_pages' => $totalPages,
            'active_job_type' => $activeJob?->job_type,
            'active_job_status' => $activeJob?->status,
            'active_job_progress' => $activeJob?->progress,
        ];
    }

    /**
     * @param  array<int, TextbookProcessingJob>  $jobs
     */
    private function processingStageMessage(string $status, ?TextbookProcessingJob $activeJob, array $jobs = []): string
    {
        if ($status === TextbookProcessingStatus::QUEUED) {
            $latestByType = $this->latestJobsByType($jobs);
            $extractJob = $latestByType['extract_text'] ?? null;
            $detectJob = $latestByType['detect_structure'] ?? null;

            if ($extractJob?->status === 'completed') {
                if ($detectJob?->status === 'processing') {
                    return 'جاري تحليل الفهرس واكتشاف الوحدات';
                }

                if ($detectJob?->status === 'queued') {
                    return 'في انتظار تحليل الفهرس...';
                }

                return 'اكتمل استخراج المحتوى — جاري التحضير للمرحلة التالية';
            }

            if ($extractJob?->status === 'processing') {
                return 'جاري استخراج محتوى الكتاب';
            }

            if ($extractJob?->status === 'queued') {
                return 'في انتظار بدء استخراج المحتوى';
            }
        }

        return match ($status) {
            TextbookProcessingStatus::UPLOADED => 'تم رفع الكتاب',
            TextbookProcessingStatus::QUEUED => 'في قائمة الانتظار',
            TextbookProcessingStatus::EXTRACTING => 'جاري استخراج محتوى الكتاب',
            TextbookProcessingStatus::ANALYZING_CONTENTS => 'جاري تحليل الفهرس',
            TextbookProcessingStatus::UNITS_DETECTED => 'جاري اكتشاف الوحدات',
            TextbookProcessingStatus::AWAITING_UNIT_REVIEW => 'مراجعة الوحدات',
            TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED => 'إدخال الوحدات يدوياً',
            TextbookProcessingStatus::FAILED => 'تعذر تحليل الكتاب',
            default => 'جاري معالجة الكتاب',
        };
    }

    /**
     * @param  array<int, TextbookProcessingJob>  $jobs
     */
    private function reconcileTextbookProcessingStatus(Textbook $textbook, array $jobs): void
    {
        $status = TextbookProcessingStatus::normalize($textbook->processing_status);
        $latestByType = $this->latestJobsByType($jobs);
        $extractJob = $latestByType['extract_text'] ?? null;
        $detectJob = $latestByType['detect_structure'] ?? null;

        $nextStatus = null;

        if ($detectJob?->status === 'completed' && $textbook->proposed_structure) {
            $current = TextbookProcessingStatus::normalize($textbook->processing_status);
            $nextStatus = in_array($current, [
                TextbookProcessingStatus::AWAITING_UNIT_REVIEW,
                TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED,
            ], true)
                ? $current
                : TextbookProcessingStatus::AWAITING_UNIT_REVIEW;
        } elseif ($detectJob?->status === 'processing') {
            $nextStatus = TextbookProcessingStatus::ANALYZING_CONTENTS;
        } elseif ($extractJob?->status === 'completed' && in_array($detectJob?->status, ['queued', 'processing'], true)) {
            $nextStatus = TextbookProcessingStatus::ANALYZING_CONTENTS;
        } elseif ($extractJob?->status === 'processing') {
            $nextStatus = TextbookProcessingStatus::EXTRACTING;
        } elseif ($status === TextbookProcessingStatus::QUEUED && $extractJob?->status === 'completed' && ! $detectJob) {
            $nextStatus = TextbookProcessingStatus::ANALYZING_CONTENTS;
        }

        if ($nextStatus === null || $status === $nextStatus) {
            return;
        }

        if ($this->processingStatusRank($status) >= $this->processingStatusRank($nextStatus)) {
            return;
        }

        $textbook->update([
            'processing_status' => $nextStatus,
            'updated_at' => now(),
        ]);
    }

    private function processingStatusRank(string $status): int
    {
        return match (TextbookProcessingStatus::normalize($status)) {
            TextbookProcessingStatus::UPLOADED => 0,
            TextbookProcessingStatus::QUEUED => 1,
            TextbookProcessingStatus::EXTRACTING => 2,
            TextbookProcessingStatus::ANALYZING_CONTENTS => 3,
            TextbookProcessingStatus::UNITS_DETECTED => 4,
            TextbookProcessingStatus::AWAITING_UNIT_REVIEW => 5,
            TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED => 5,
            TextbookProcessingStatus::UNITS_APPROVED => 6,
            TextbookProcessingStatus::GENERATING_QUESTIONS => 7,
            TextbookProcessingStatus::AWAITING_QUESTION_REVIEW => 8,
            TextbookProcessingStatus::READY => 9,
            default => 0,
        };
    }

    /**
     * @param  array<int, TextbookProcessingJob>  $jobs
     * @return array<string, TextbookProcessingJob>
     */
    private function latestJobsByType(array $jobs): array
    {
        $latest = [];

        foreach ($jobs as $job) {
            if (! isset($latest[$job->job_type])) {
                $latest[$job->job_type] = $job;
            }
        }

        return $latest;
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitizeForClient(Textbook $textbook, bool $lightweight = false): array
    {
        $internalStatus = TextbookProcessingStatus::normalize($textbook->processing_status);
        $workflowStatus = TextbookProductStatus::fromInternal($internalStatus);

        return [
            'id' => $textbook->id,
            'title' => $textbook->title,
            'academic_stage' => $textbook->academic_stage,
            'grade' => $textbook->grade,
            'subject_id' => $textbook->subject_id,
            'academic_year' => $textbook->academic_year,
            'semester' => $textbook->semester,
            'language' => $textbook->language,
            'storage_bucket' => $textbook->storage_bucket,
            'storage_path' => $textbook->storage_path,
            'file_size_bytes' => $textbook->file_size_bytes,
            'workflow_status' => $workflowStatus,
            'workflow_message' => TextbookProductStatus::message($workflowStatus, $textbook->last_error),
            'processing_status' => $workflowStatus,
            'requires_manual_structure' => $internalStatus === TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED,
            'structure_status' => $textbook->structure_status,
            'created_by' => $textbook->created_by,
            'created_at' => $textbook->created_at?->toIso8601String(),
            'updated_at' => $textbook->updated_at?->toIso8601String(),
            'has_proposed_structure' => $lightweight
                ? in_array($textbook->structure_status, ['review_required', 'approved'], true)
                : (bool) $textbook->proposed_structure,
            'has_approved_structure' => $lightweight
                ? $textbook->structure_status === 'approved'
                : (bool) $textbook->approved_structure,
            'has_stored_file' => $lightweight ? (bool) $textbook->storage_path : $this->files->exists($textbook),
            'last_error' => $textbook->last_error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeJob(TextbookProcessingJob $job): array
    {
        return [
            'id' => $job->id,
            'job_type' => $job->job_type,
            'status' => $job->status,
            'progress' => $job->progress,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
        ];
    }

    private function assertAdminConfigured(): void
    {
        DatabaseConfigured::assert();
    }
}
