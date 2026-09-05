<?php

namespace App\Services\Curriculum;

use App\Models\Textbook;
use App\Services\Ai\AiClient;
use App\Support\TextbookProcessingStatus;
use Illuminate\Support\Facades\DB;

class TextbookUnitDiagnosticService
{
    private const MIN_UNIT_CONTENT_CHARS = 50;

    public function __construct(
        private readonly TextbookFileStorageService $files,
        private readonly UnitGenerationOrchestratorService $orchestrator,
        private readonly AiClient $ai,
    ) {}

    /**
     * @return array{textbook_id: string, unit_key: string}
     */
    public function resolveUnitReference(string $unitIdentifier, ?string $textbookId = null): array
    {
        if (str_contains($unitIdentifier, ':')) {
            [$textbookId, $unitKey] = explode(':', $unitIdentifier, 2);

            return [
                'textbook_id' => trim($textbookId),
                'unit_key' => trim($unitKey),
            ];
        }

        if ($textbookId) {
            return [
                'textbook_id' => trim($textbookId),
                'unit_key' => trim($unitIdentifier),
            ];
        }

        $statusRow = DB::table('curriculum_unit_generation_status')
            ->where('id', $unitIdentifier)
            ->orWhere('unit_key', $unitIdentifier)
            ->orderByDesc('updated_at')
            ->first();

        if ($statusRow) {
            return [
                'textbook_id' => (string) $statusRow->textbook_id,
                'unit_key' => (string) $statusRow->unit_key,
            ];
        }

        throw new \InvalidArgumentException(
            'Provide unit as textbook_id:unit_key, pass --textbook=UUID with unit_key, or use a curriculum_unit_generation_status id.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnose(string $textbookId, string $unitKey): array
    {
        $textbook = Textbook::query()->find($textbookId);
        $unitNode = $this->findUnitNode($textbook, $unitKey);
        $unitStatus = DB::table('curriculum_unit_generation_status')
            ->where('textbook_id', $textbookId)
            ->where('unit_key', $unitKey)
            ->first();

        $pdfExists = false;
        $absolutePath = null;

        if ($textbook) {
            try {
                $absolutePath = $this->files->absolutePath($textbook);
                $pdfExists = is_file($absolutePath);
            } catch (\Throwable) {
                $pdfExists = false;
            }
        }

        $chunks = DB::table('textbook_content_chunks')
            ->where('textbook_id', $textbookId)
            ->where('unit_key', $unitKey)
            ->orderBy('source_page_start')
            ->get();

        $contentChars = (int) $chunks->sum(fn ($row) => mb_strlen((string) ($row->content ?? '')));
        $pageStart = $chunks->min('source_page_start');
        $pageEnd = $chunks->max('source_page_end');

        if ($pageStart === null && $unitNode) {
            $pageStart = $this->minPageInNode($unitNode);
            $pageEnd = $this->maxPageInNode($unitNode);
        }

        $pageCount = (int) DB::table('textbook_pages')
            ->where('textbook_id', $textbookId)
            ->count();

        $buildChunksJob = DB::table('textbook_processing_jobs')
            ->where('textbook_id', $textbookId)
            ->where('job_type', 'build_chunks')
            ->orderByDesc('created_at')
            ->first(['status', 'error_message', 'completed_at']);

        $pipelineJobs = DB::table('textbook_processing_jobs')
            ->where('textbook_id', $textbookId)
            ->whereIn('job_type', ['generate_questions', 'generate_unit_questions', 'build_chunks'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'job_type', 'status', 'error_message', 'created_at', 'started_at', 'completed_at']);

        $laravelJobs = DB::table('jobs')
            ->where('payload', 'like', '%'.$textbookId.'%')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'queue', 'attempts', 'reserved_at', 'created_at']);

        $aiReachable = $this->probeAiReachability();

        $questionCount = (int) DB::table('ai_generated_questions')
            ->where('textbook_id', $textbookId)
            ->where('unit_key', $unitKey)
            ->count();

        $promotedCount = (int) DB::table('ai_generated_questions')
            ->where('textbook_id', $textbookId)
            ->where('unit_key', $unitKey)
            ->whereNotNull('question_id')
            ->count();

        $stages = $this->buildStageChecklist(
            $textbook,
            $unitNode,
            $pdfExists,
            $chunks->isNotEmpty(),
            $contentChars,
            $pageCount,
            $pipelineJobs,
            $laravelJobs,
            $aiReachable['configured'],
            $aiReachable['reachable'],
            $questionCount,
            $unitStatus,
            $buildChunksJob,
        );

        $approvedUnits = $this->orchestrator->extractUnitsFromStructure(
            is_array($textbook?->approved_structure) ? $textbook->approved_structure : null
        );
        $proposedUnits = $this->orchestrator->extractUnitsFromStructure(
            is_array($textbook?->proposed_structure) ? $textbook->proposed_structure : null
        );

        return [
            'textbook_id' => $textbookId,
            'unit_key' => $unitKey,
            'unit_title' => $unitNode['title'] ?? ($unitStatus->unit_title ?? null),
            'textbook_exists' => $textbook !== null,
            'textbook_title' => $textbook?->title,
            'processing_status' => TextbookProcessingStatus::normalize($textbook?->processing_status),
            'structure_status' => $textbook?->structure_status,
            'structure_approved' => $textbook?->structure_status === 'approved',
            'proposed_unit_count' => count($proposedUnits),
            'approved_unit_count' => count($approvedUnits),
            'textbook_page_count' => $pageCount,
            'build_chunks_status' => $buildChunksJob?->status,
            'build_chunks_error' => $buildChunksJob?->error_message,
            'stored_pdf_exists' => $pdfExists,
            'storage_path' => $textbook?->storage_path,
            'absolute_pdf_path' => $absolutePath,
            'unit_in_approved_structure' => $unitNode !== null,
            'unit_start_page' => $pageStart,
            'unit_end_page' => $pageEnd,
            'chunk_count' => $chunks->count(),
            'extracted_content_exists' => $contentChars >= self::MIN_UNIT_CONTENT_CHARS,
            'extracted_content_char_count' => $contentChars,
            'unit_content_ready_for_ai' => $contentChars >= self::MIN_UNIT_CONTENT_CHARS,
            'queue_connection' => config('queue.default'),
            'jobs_table_count' => DB::table('jobs')->count(),
            'failed_jobs_count' => DB::table('failed_jobs')->count(),
            'pipeline_jobs' => $pipelineJobs,
            'laravel_jobs_for_textbook' => $laravelJobs,
            'ai_provider' => $this->ai->provider(),
            'ai_configured' => $this->ai->isConfigured(),
            'ai_generation_model' => $this->ai->generationModel(),
            'ai_reachable' => $aiReachable['reachable'],
            'ai_probe_message' => $aiReachable['message'],
            'unit_generation_status' => $unitStatus?->status,
            'unit_generation_error' => $unitStatus?->last_error,
            'ai_generated_question_count' => $questionCount,
            'promoted_question_count' => $promotedCount,
            'stages' => $stages,
            'likely_failure_stage' => $this->firstFailedStage($stages),
        ];
    }

    /**
     * @return array{configured: bool, reachable: bool, message: string}
     */
    public function probeAiReachability(): array
    {
        if (! $this->ai->isConfigured()) {
            return [
                'configured' => false,
                'reachable' => false,
                'message' => 'AI provider key is not configured',
            ];
        }

        try {
            $content = $this->ai->chat([
                ['role' => 'user', 'content' => 'Reply with JSON only: {"ok":true}'],
            ], [
                'model' => $this->ai->generationModel(),
                'temperature' => 0,
                'json' => true,
                'max_tokens' => 16,
            ]);

            return [
                'configured' => true,
                'reachable' => $content !== '',
                'message' => $content !== '' ? 'AI provider responded successfully' : 'AI provider returned empty content',
            ];
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            if (property_exists($exception, 'providerStatus') && $exception->providerStatus) {
                $message .= ' (HTTP '.$exception->providerStatus.')';
            }

            return [
                'configured' => true,
                'reachable' => false,
                'message' => $message,
            ];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadUnitChunks(string $textbookId, string $unitKey): array
    {
        return DB::table('textbook_content_chunks')
            ->where('textbook_id', $textbookId)
            ->where('unit_key', $unitKey)
            ->orderBy('source_page_start')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function assertUnitContentAvailable(string $textbookId, string $unitKey): int
    {
        $chars = (int) DB::table('textbook_content_chunks')
            ->where('textbook_id', $textbookId)
            ->where('unit_key', $unitKey)
            ->get()
            ->sum(fn ($row) => mb_strlen((string) ($row->content ?? '')));

        if ($chars < self::MIN_UNIT_CONTENT_CHARS) {
            throw new \RuntimeException('Unit content is unavailable for AI generation.');
        }

        return $chars;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUnitNode(?Textbook $textbook, string $unitKey): ?array
    {
        if (! $textbook) {
            return null;
        }

        $structure = $textbook->approved_structure ?? $textbook->proposed_structure;
        $units = $this->orchestrator->extractUnitsFromStructure(is_array($structure) ? $structure : null);

        foreach ($units as $unit) {
            if ($unit['unit_key'] === $unitKey) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $pipelineJobs
     * @param  \Illuminate\Support\Collection<int, object>  $laravelJobs
     * @param  object|null  $unitStatus
     * @return array<int, array{stage: string, ok: bool, detail: string}>
     */
    private function buildStageChecklist(
        ?Textbook $textbook,
        ?array $unitNode,
        bool $pdfExists,
        bool $hasChunks,
        int $contentChars,
        int $pageCount,
        $pipelineJobs,
        $laravelJobs,
        bool $aiConfigured,
        bool $aiReachable,
        int $questionCount,
        $unitStatus,
        $buildChunksJob,
    ): array {
        $structureApproved = $textbook?->structure_status === 'approved';
        $hasQueuedPipeline = $pipelineJobs->contains(fn ($job) => in_array($job->status, ['queued', 'processing'], true));
        $hasFailedPipeline = $pipelineJobs->contains(fn ($job) => $job->status === 'failed');
        $latestPipelineError = $pipelineJobs->firstWhere('status', 'failed')?->error_message;
        $buildChunksComplete = ($buildChunksJob?->status ?? null) === 'completed';

        return [
            ['stage' => '1. Textbook record exists', 'ok' => $textbook !== null, 'detail' => $textbook ? 'found' : 'missing'],
            ['stage' => '2. Stored PDF exists', 'ok' => $pdfExists, 'detail' => $pdfExists ? 'yes' : 'no'],
            ['stage' => '3. Unit record exists', 'ok' => $unitNode !== null, 'detail' => $unitNode ? ($unitNode['unit_title'] ?? $unitNode['unit_key']) : 'not in structure'],
            ['stage' => '3b. Structure approved', 'ok' => $structureApproved, 'detail' => $structureApproved ? 'approved' : (string) ($textbook?->structure_status ?? 'unknown')],
            ['stage' => '4. Unit page range/content exists', 'ok' => $hasChunks, 'detail' => $hasChunks ? "{$contentChars} chars in chunks" : "pages_in_db={$pageCount}, chunks=0"],
            ['stage' => '4b. build_chunks completed', 'ok' => $buildChunksComplete || $hasChunks, 'detail' => (string) ($buildChunksJob?->status ?? 'not run')],
            ['stage' => '5. Processing job dispatched', 'ok' => $pipelineJobs->isNotEmpty(), 'detail' => (string) $pipelineJobs->count().' pipeline job(s)'],
            ['stage' => '6. Queue worker receives job', 'ok' => $laravelJobs->isNotEmpty() || $pipelineJobs->contains(fn ($j) => $j->status === 'completed'), 'detail' => 'jobs_table='.$laravelJobs->count()],
            ['stage' => '7. PDF/unit extraction succeeds', 'ok' => $hasChunks && $contentChars >= self::MIN_UNIT_CONTENT_CHARS, 'detail' => "content_chars={$contentChars}"],
            ['stage' => '8. AI service has valid unit content', 'ok' => $contentChars >= self::MIN_UNIT_CONTENT_CHARS, 'detail' => $contentChars >= self::MIN_UNIT_CONTENT_CHARS ? 'ready' : 'Unit content is unavailable for AI generation.'],
            ['stage' => '9. AI API configured/reachable', 'ok' => $aiConfigured && $aiReachable, 'detail' => $aiConfigured ? ($aiReachable ? 'reachable' : 'configured but probe failed') : 'not configured'],
            ['stage' => '10. Structured AI response parseable', 'ok' => $questionCount > 0 || ! $hasFailedPipeline, 'detail' => $hasFailedPipeline ? (string) $latestPipelineError : 'no parse failure recorded'],
            ['stage' => '11. Questions pass validation', 'ok' => $questionCount > 0, 'detail' => "ai_generated_questions={$questionCount}"],
            ['stage' => '12. Questions saved to database', 'ok' => $questionCount > 0, 'detail' => 'staging table ai_generated_questions'],
            ['stage' => '13. Status completed/awaiting_review', 'ok' => in_array($unitStatus?->status, ['completed', 'building_sets'], true)
                || in_array(TextbookProcessingStatus::normalize($textbook?->processing_status), [
                    TextbookProcessingStatus::AWAITING_QUESTION_REVIEW,
                    TextbookProcessingStatus::READY,
                ], true), 'detail' => (string) ($unitStatus?->status ?? $textbook?->processing_status ?? 'unknown')],
        ];
    }

    /**
     * @param  array<int, array{stage: string, ok: bool, detail: string}>  $stages
     */
    private function firstFailedStage(array $stages): ?string
    {
        foreach ($stages as $stage) {
            if (! $stage['ok']) {
                return $stage['stage'].' — '.$stage['detail'];
            }
        }

        return null;
    }

  /**
     * @param  array<string, mixed>  $node
     */
    private function minPageInNode(array $node): ?int
    {
        $pages = [];
        app(ChunkingService::class)->walkStructure($node, function (array $child) use (&$pages): void {
            if (isset($child['start_page'])) {
                $pages[] = (int) $child['start_page'];
            }
        });

        return $pages === [] ? null : min($pages);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function maxPageInNode(array $node): ?int
    {
        $pages = [];
        app(ChunkingService::class)->walkStructure($node, function (array $child) use (&$pages): void {
            if (isset($child['end_page'])) {
                $pages[] = (int) $child['end_page'];
            }
        });

        return $pages === [] ? null : max($pages);
    }
}
