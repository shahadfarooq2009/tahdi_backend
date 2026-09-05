<?php

namespace App\Services\Curriculum;

use App\Exceptions\NotFoundException;
use App\Exceptions\ServiceUnavailableException;
use App\Exceptions\ValidationException;
use App\Jobs\RunTextbookProcessingJob;
use App\Models\Textbook;
use App\Models\TextbookProcessingJob;
use App\Support\DatabaseConfigured;
use App\Support\TextbookProcessingStage;
use App\Support\TextbookProcessingStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TextbookJobService
{
    private const PIPELINE_JOB_TYPES = [
        'extract_text',
        'detect_structure',
        'build_chunks',
    ];

    public function __construct(
        private readonly LocalQueueWorkerLauncher $localQueue,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(string $textbookId, string $jobType, array $payload = [], ?string $actorUserId = null): TextbookProcessingJob
    {
        $this->assertAdminConfigured();

        if ($this->isPipelineJobActivelyProcessing($textbookId, $jobType)) {
            throw new ValidationException('Textbook processing is already in progress');
        }

        $existingQueued = TextbookProcessingJob::query()
            ->where('textbook_id', $textbookId)
            ->where('job_type', $jobType)
            ->where('status', 'queued')
            ->orderByDesc('created_at')
            ->first();

        if ($existingQueued) {
            return $this->ensurePipelineJobDispatched($existingQueued);
        }

        $job = TextbookProcessingJob::query()->create([
            'textbook_id' => $textbookId,
            'job_type' => $jobType,
            'status' => 'queued',
            'payload' => $payload,
            'created_by' => $actorUserId,
        ]);

        try {
            $this->dispatchLaravelJob($job);
            $this->markTextbookQueuedIfPipelineStage($job);
        } catch (Throwable $exception) {
            $this->markFailed(
                $job->id,
                'Failed to queue processing job: '.$exception->getMessage(),
                $textbookId,
            );

            Log::error('Textbook pipeline job dispatch failed', [
                'textbook_id' => $textbookId,
                'processing_job_id' => $job->id,
                'job_type' => $jobType,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new ServiceUnavailableException(
                'Unable to queue textbook processing job. Check queue configuration and logs.',
            );
        }

        $this->localQueue->kickIfLocalWebRequest();
        $this->localQueue->ensureWorkerRunning();

        return $job;
    }

    /**
     * Ensure queued pipeline jobs for a textbook have a matching Laravel queue row.
     */
    public function recoverStuckQueue(string $textbookId): void
    {
        if (config('queue.default') !== 'database') {
            return;
        }

        $queuedPipelineJobs = TextbookProcessingJob::query()
            ->where('textbook_id', $textbookId)
            ->where('status', 'queued')
            ->whereIn('job_type', self::PIPELINE_JOB_TYPES)
            ->orderBy('created_at')
            ->get();

        if ($queuedPipelineJobs->isEmpty()) {
            return;
        }

        foreach ($queuedPipelineJobs as $pipelineJob) {
            if ($this->hasLaravelJobForProcessingJob($pipelineJob->id)) {
                continue;
            }

            Log::warning('Re-dispatching orphaned textbook pipeline job', [
                'textbook_id' => $textbookId,
                'processing_job_id' => $pipelineJob->id,
                'job_type' => $pipelineJob->job_type,
            ]);

            try {
                $this->dispatchLaravelJob($pipelineJob);
                $this->markTextbookQueuedIfPipelineStage($pipelineJob);
            } catch (Throwable $exception) {
                $this->markFailed(
                    $pipelineJob->id,
                    'Failed to re-queue processing job: '.$exception->getMessage(),
                    $textbookId,
                );

                Log::error('Orphaned textbook pipeline job re-dispatch failed', [
                    'textbook_id' => $textbookId,
                    'processing_job_id' => $pipelineJob->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($this->hasPendingLaravelJobForTextbook($textbookId)) {
            $this->localQueue->ensureWorkerRunning();
        }
    }

    /**
     * @return array<int, TextbookProcessingJob>
     */
    public function latestForTextbook(string $textbookId, int $limit = 20): array
    {
        return TextbookProcessingJob::query()
            ->where('textbook_id', $textbookId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function updateProgress(string $jobId, int $progress): void
    {
        TextbookProcessingJob::query()
            ->where('id', $jobId)
            ->update([
                'progress' => max(0, min(100, $progress)),
                'updated_at' => now(),
            ]);
    }

    public function markProcessing(string $jobId): void
    {
        TextbookProcessingJob::query()
            ->where('id', $jobId)
            ->update([
                'status' => 'processing',
                'started_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function markCompleted(string $jobId): void
    {
        TextbookProcessingJob::query()
            ->where('id', $jobId)
            ->update([
                'status' => 'completed',
                'progress' => 100,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function markFailed(string $jobId, string $message, ?string $textbookId = null): void
    {
        $job = TextbookProcessingJob::query()->find($jobId);

        if (! $job) {
            return;
        }

        $job->update([
            'status' => 'failed',
            'error_message' => mb_substr($message, 0, 1000),
            'completed_at' => now(),
            'updated_at' => now(),
            'retry_count' => ($job->retry_count ?? 0) + 1,
        ]);

        $targetTextbookId = $textbookId ?? $job->textbook_id;

        if ($targetTextbookId) {
            Textbook::query()
                ->where('id', $targetTextbookId)
                ->update([
                    'processing_status' => TextbookProcessingStatus::FAILED,
                    'last_error' => mb_substr($message, 0, 1000),
                    'updated_at' => now(),
                ]);

            $failedStage = match ($job->job_type) {
                'detect_structure' => TextbookProcessingStage::DETECT_TOC,
                default => TextbookProcessingStage::EXTRACT_TEXT,
            };

            app(TextbookProcessingTimelineService::class)->markFailed(
                $targetTextbookId,
                $failedStage,
                $message,
            );
        }
    }

    public function retryFailedForTextbook(string $textbookId): TextbookProcessingJob
    {
        $failed = TextbookProcessingJob::query()
            ->where('textbook_id', $textbookId)
            ->where('status', 'failed')
            ->orderByDesc('created_at')
            ->first();

        if (! $failed) {
            throw new ValidationException('No failed job to retry');
        }

        return $this->enqueue(
            $failed->textbook_id,
            $failed->job_type,
            $failed->payload ?? [],
            $failed->created_by
        );
    }

    public function getOrFail(string $jobId): TextbookProcessingJob
    {
        $job = TextbookProcessingJob::query()->find($jobId);

        if (! $job) {
            throw new NotFoundException('Job not found');
        }

        return $job;
    }

    private function ensurePipelineJobDispatched(TextbookProcessingJob $pipelineJob): TextbookProcessingJob
    {
        if ($this->hasLaravelJobForProcessingJob($pipelineJob->id)) {
            $this->markTextbookQueuedIfPipelineStage($pipelineJob);
            $this->localQueue->ensureWorkerRunning();

            return $pipelineJob;
        }

        try {
            $this->dispatchLaravelJob($pipelineJob);
            $this->markTextbookQueuedIfPipelineStage($pipelineJob);
        } catch (Throwable $exception) {
            $this->markFailed(
                $pipelineJob->id,
                'Failed to re-queue processing job: '.$exception->getMessage(),
                $pipelineJob->textbook_id,
            );

            Log::error('Existing queued pipeline job could not be dispatched', [
                'textbook_id' => $pipelineJob->textbook_id,
                'processing_job_id' => $pipelineJob->id,
                'job_type' => $pipelineJob->job_type,
                'message' => $exception->getMessage(),
            ]);

            throw new ServiceUnavailableException(
                'Unable to queue textbook processing job. Check queue configuration and logs.',
            );
        }

        $this->localQueue->ensureWorkerRunning();

        return $pipelineJob;
    }

    private function dispatchLaravelJob(TextbookProcessingJob $pipelineJob): void
    {
        $connection = 'database';
        $queue = $this->queueNameForJobType($pipelineJob->job_type);
        $jobsBefore = (int) DB::table('jobs')->count();

        RunTextbookProcessingJob::dispatch($pipelineJob->id)
            ->onConnection($connection)
            ->onQueue($queue);

        $hasQueueRow = $this->hasLaravelJobForProcessingJob($pipelineJob->id);
        $alreadyProcessing = TextbookProcessingJob::query()
            ->where('id', $pipelineJob->id)
            ->where('status', 'processing')
            ->exists();

        if (! $hasQueueRow && ! $alreadyProcessing) {
            throw new \RuntimeException(
                'Laravel queue job was not persisted to the jobs table '
                ."(connection={$connection}, queue={$queue}, jobs_before={$jobsBefore})"
            );
        }

        Log::info('Textbook Laravel queue job dispatched', [
            'textbook_id' => $pipelineJob->textbook_id,
            'processing_job_id' => $pipelineJob->id,
            'job_type' => $pipelineJob->job_type,
            'connection' => $connection,
            'queue' => $queue,
            'jobs_table_count' => DB::table('jobs')->count(),
        ]);
    }

    private function markTextbookQueuedIfPipelineStage(TextbookProcessingJob $pipelineJob): void
    {
        if (! in_array($pipelineJob->job_type, self::PIPELINE_JOB_TYPES, true)) {
            return;
        }

        $targetStatus = match ($pipelineJob->job_type) {
            'extract_text' => TextbookProcessingStatus::QUEUED,
            'detect_structure' => TextbookProcessingStatus::ANALYZING_CONTENTS,
            'build_chunks' => TextbookProcessingStatus::UNITS_APPROVED,
            default => null,
        };

        if ($targetStatus === null) {
            return;
        }

        $textbook = Textbook::query()->find($pipelineJob->textbook_id);

        if (! $textbook) {
            return;
        }

        $currentStatus = TextbookProcessingStatus::normalize($textbook->processing_status);

        // Never move the textbook backwards when dispatching the next pipeline stage.
        if ($this->processingStatusRank($currentStatus) >= $this->processingStatusRank($targetStatus)) {
            return;
        }

        Textbook::query()
            ->where('id', $pipelineJob->textbook_id)
            ->update([
                'processing_status' => $targetStatus,
                'last_error' => null,
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

    private function isPipelineJobActivelyProcessing(string $textbookId, string $jobType): bool
    {
        return TextbookProcessingJob::query()
            ->where('textbook_id', $textbookId)
            ->where('job_type', $jobType)
            ->where('status', 'processing')
            ->exists();
    }

    private function hasPendingLaravelJobForTextbook(string $textbookId): bool
    {
        $queuedPipelineJobIds = TextbookProcessingJob::query()
            ->where('textbook_id', $textbookId)
            ->where('status', 'queued')
            ->pluck('id');

        foreach ($queuedPipelineJobIds as $processingJobId) {
            if ($this->hasLaravelJobForProcessingJob((string) $processingJobId)) {
                return true;
            }
        }

        return false;
    }

    private function hasLaravelJobForProcessingJob(string $processingJobId): bool
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%'.$processingJobId.'%')
            ->exists();
    }

    private function queueNameForJobType(string $jobType): string
    {
        return match ($jobType) {
            'extract_text' => 'textbook-extraction',
            'detect_structure', 'build_chunks' => 'textbook-analysis',
            'generate_questions', 'generate_unit_questions' => 'question-generation',
            default => 'default',
        };
    }

    private function assertAdminConfigured(): void
    {
        DatabaseConfigured::assert();
    }
}
