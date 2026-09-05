<?php

namespace App\Jobs;

use App\Models\TextbookProcessingJob;
use App\Services\Curriculum\TextbookAiService;
use App\Services\Curriculum\TextbookJobService;
use App\Services\Curriculum\TextbookService;
use App\Services\Curriculum\UnitGenerationOrchestratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunTextbookProcessingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly string $processingJobId,
    ) {
        $this->onConnection('database');
    }

    public function handle(
        TextbookService $textbooks,
        TextbookJobService $jobs,
        TextbookAiService $textbookAi,
        UnitGenerationOrchestratorService $unitGeneration,
    ): void {
        $job = $jobs->getOrFail($this->processingJobId);

        if ($job->status !== 'queued') {
            return;
        }

        $alreadyProcessing = TextbookProcessingJob::query()
            ->where('textbook_id', $job->textbook_id)
            ->where('job_type', $job->job_type)
            ->where('status', 'processing')
            ->where('id', '!=', $job->id)
            ->exists();

        if ($alreadyProcessing) {
            logger()->warning('RunTextbookProcessingJob skipped — duplicate in-flight job', [
                'processing_job_id' => $job->id,
                'textbook_id' => $job->textbook_id,
                'job_type' => $job->job_type,
            ]);

            return;
        }

        logger()->info('RunTextbookProcessingJob started', [
            'processing_job_id' => $job->id,
            'textbook_id' => $job->textbook_id,
            'job_type' => $job->job_type,
        ]);

        $jobs->markProcessing($job->id);

        try {
            match ($job->job_type) {
                'extract_text' => $textbooks->runExtractText($job),
                'detect_structure' => $textbooks->runDetectStructure($job),
                'build_chunks' => $textbooks->runBuildChunks($job),
                'generate_questions' => $textbookAi->runGenerateQuestionsJob($job),
                'generate_unit_questions' => $unitGeneration->runGenerateUnitQuestionsJob($job),
                default => throw new \RuntimeException("Unsupported job type: {$job->job_type}"),
            };

            $jobs->markCompleted($job->id);
            $textbooks->ensurePipelineContinuity($job->fresh());

            logger()->info('RunTextbookProcessingJob completed', [
                'processing_job_id' => $job->id,
                'textbook_id' => $job->textbook_id,
                'job_type' => $job->job_type,
            ]);
        } catch (Throwable $exception) {
            logger()->error('RunTextbookProcessingJob failed', [
                'processing_job_id' => $job->id,
                'textbook_id' => $job->textbook_id,
                'job_type' => $job->job_type,
                'message' => $exception->getMessage(),
            ]);

            $jobs->markFailed($job->id, $exception->getMessage(), $job->textbook_id);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        app(TextbookJobService::class)->markFailed(
            $this->processingJobId,
            $exception->getMessage()
        );
    }
}
