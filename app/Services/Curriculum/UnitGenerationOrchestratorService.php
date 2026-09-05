<?php

namespace App\Services\Curriculum;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Textbook;
use App\Models\TextbookProcessingJob;
use App\Services\Admin\QuestionService;
use App\Services\Ai\GroundedQuestionGenerationService;
use App\Services\Ai\QuestionValidationService;
use App\Support\ChunkProvenanceResolver;
use App\Support\CurriculumConfig;
use App\Support\QuestionGradeMapper;
use App\Support\QuestionTypeMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UnitGenerationOrchestratorService
{
    public function __construct(
        private readonly ChunkingService $chunking,
        private readonly TextbookJobService $jobs,
        private readonly GroundedQuestionGenerationService $generator,
        private readonly QuestionValidationService $validator,
        private readonly DuplicateDetectionService $duplicates,
        private readonly ReviewSetBuilderService $reviewSetBuilder,
        private readonly ChapterMappingService $chapterMapping,
        private readonly QuestionService $questions,
        private readonly TextbookService $textbooks,
        private readonly ChunkProvenanceResolver $provenance,
    ) {}

    /**
     * @param  array<string, mixed>|null  $structure
     * @return array<int, array{unit_key: string, unit_title: string, lesson_keys: string[]}>
     */
    public function extractUnitsFromStructure(?array $structure): array
    {
        if (! is_array($structure['children'] ?? null)) {
            return [];
        }

        $units = [];

        foreach ($structure['children'] as $child) {
            if (($child['type'] ?? null) !== 'unit') {
                continue;
            }

            $lessonKeys = [];
            $this->chunking->walkStructure($child, function (array $node) use (&$lessonKeys): void {
                if (($node['type'] ?? null) === 'lesson') {
                    $lessonKeys[] = $node['key'];
                }
            });

            $units[] = [
                'unit_key' => (string) $child['key'],
                'unit_title' => (string) ($child['title'] ?? $child['key']),
                'lesson_keys' => $lessonKeys,
            ];
        }

        return $units;
    }

    /**
     * @param  string[]  $lessonKeys
     * @return array<int, array{lesson_key: ?string, count: int}>
     */
    public function allocateLessonSlots(array $lessonKeys, int $totalNeeded): array
    {
        if ($lessonKeys === []) {
            return [['lesson_key' => null, 'count' => $totalNeeded]];
        }

        $base = intdiv($totalNeeded, count($lessonKeys));
        $remainder = $totalNeeded % count($lessonKeys);
        $slots = [];

        foreach ($lessonKeys as $lessonKey) {
            $extra = $remainder > 0 ? 1 : 0;

            if ($remainder > 0) {
                $remainder--;
            }

            $slots[] = ['lesson_key' => $lessonKey, 'count' => $base + $extra];
        }

        return $slots;
    }

    /**
     * Pipeline auto-start after build_chunks — no permission gate (internal only).
     */
    public function enqueueAllUnitsPipeline(string $textbookId, string $actorUserId): TextbookProcessingJob
    {
        $textbook = $this->textbooks->getOrFail($textbookId);

        if ($textbook->structure_status !== 'approved') {
            throw new ValidationException('Textbook structure must be approved before unit generation');
        }

        $this->assertUnitChunksAvailable($textbookId);

        $units = $this->extractUnitsFromStructure($textbook->approved_structure);

        if ($units === []) {
            throw new ValidationException('No units found in approved structure');
        }

        foreach ($units as $unit) {
            $this->upsertUnitGenerationStatus($textbookId, $unit['unit_key'], [
                'unit_title' => $unit['unit_title'],
                'status' => 'pending',
                'last_error' => null,
                'target_questions' => config('curriculum.target_questions_per_unit', 60),
            ]);
        }

        $this->textbooks->markGeneratingQuestions($textbookId);

        return $this->jobs->enqueue($textbookId, 'generate_unit_questions', [
            'unit_keys' => array_column($units, 'unit_key'),
            'auto_promote' => true,
        ], $actorUserId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function requestUnitQuestionGeneration(string $textbookId, array $payload, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canUseAI')) {
            throw new ForbiddenException();
        }

        $textbook = $this->textbooks->getOrFail($textbookId);

        if ($textbook->structure_status !== 'approved') {
            throw new ValidationException('Textbook structure must be approved before unit generation');
        }

        $this->assertUnitChunksAvailable($textbookId);

        $units = $this->extractUnitsFromStructure($textbook->approved_structure);

        if ($units === []) {
            throw new ValidationException('No units found in approved structure');
        }

        $targetUnits = ! empty($payload['unit_key'])
            ? array_values(array_filter($units, fn ($unit) => $unit['unit_key'] === $payload['unit_key']))
            : $units;

        if ($targetUnits === []) {
            throw new ValidationException('Unit not found in textbook structure');
        }

        foreach ($targetUnits as $unit) {
            $this->upsertUnitGenerationStatus($textbookId, $unit['unit_key'], [
                'unit_title' => $unit['unit_title'],
                'status' => 'pending',
                'last_error' => null,
                'target_questions' => config('curriculum.target_questions_per_unit', 60),
            ]);
        }

        $this->textbooks->markGeneratingQuestions($textbookId);

        $job = $this->jobs->enqueue($textbookId, 'generate_unit_questions', [
            'unit_keys' => array_column($targetUnits, 'unit_key'),
            'auto_promote' => ($payload['auto_promote'] ?? true) !== false,
        ], $actor['actorUserId']);

        return [
            'job_id' => $job->id,
            'units' => array_column($targetUnits, 'unit_key'),
            'config' => CurriculumConfig::publicConfig(),
        ];
    }

    public function runGenerateUnitQuestionsJob(TextbookProcessingJob $job): array
    {
        $textbook = $this->textbooks->getOrFail($job->textbook_id);
        $unitKeys = $job->payload['unit_keys'] ?? [];
        $units = $this->extractUnitsFromStructure($textbook->approved_structure);

        if ($unitKeys !== []) {
            $units = array_values(array_filter($units, fn ($unit) => in_array($unit['unit_key'], $unitKeys, true)));
        }

        $results = [];

        foreach ($units as $index => $unit) {
            try {
                $results[] = $this->generateQuestionsForUnit(
                    $textbook,
                    $unit['unit_key'],
                    $unit['unit_title'],
                    $job->payload ?? [],
                    $job->created_by
                );
            } catch (\Throwable $exception) {
                $this->upsertUnitGenerationStatus($textbook->id, $unit['unit_key'], [
                    'status' => 'failed',
                    'last_error' => mb_substr($exception->getMessage(), 0, 1000),
                ]);

                throw $exception;
            }

            $this->jobs->updateProgress($job->id, (int) round((($index + 1) / count($units)) * 100));
        }

        $this->textbooks->markAwaitingQuestionReview($textbook->id);

        return ['units' => $results];
    }

    private function assertUnitChunksAvailable(string $textbookId): void
    {
        $chunkCount = (int) DB::table('textbook_content_chunks')
            ->where('textbook_id', $textbookId)
            ->count();

        if ($chunkCount === 0) {
            throw new ValidationException(
                'No content chunks found for this textbook. Approve units and wait for build_chunks to finish.'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array{sets: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function persistReviewSetsForUnit(string $textbookId, string $unitKey, string $unitTitle, array $questions): array
    {
        $candidates = array_values(array_filter(
            array_map(fn ($question) => [
                'id' => $question['id'],
                'question_text' => $question['question_text'],
                'answer_text' => $question['answer_text'],
                'points_value' => (int) $question['points_value'],
                'lesson_key' => $question['lesson_key'] ?? null,
                'unit_key' => $unitKey,
                'validation_status' => $question['validation_status'],
                'question_id' => $question['question_id'] ?? null,
            ], $questions),
            fn ($question) => ! in_array($question['validation_status'], ['rejected'], true)
        ));

        $lessonKeys = array_values(array_unique(array_filter(array_column($candidates, 'lesson_key'))));
        $builtSets = $this->reviewSetBuilder->buildReviewSetsFromQuestions($candidates, ['lessonKeys' => $lessonKeys]);
        $summary = $this->reviewSetBuilder->summarizeReviewSets($builtSets);

        DB::table('curriculum_review_sets')
            ->where('textbook_id', $textbookId)
            ->where('unit_key', $unitKey)
            ->delete();

        foreach ($builtSets as $builtSet) {
            $reviewSetId = (string) Str::uuid();

            DB::table('curriculum_review_sets')->insert([
                'id' => $reviewSetId,
                'textbook_id' => $textbookId,
                'unit_key' => $unitKey,
                'unit_title' => $unitTitle,
                'sequence_number' => $builtSet['sequence_number'],
                'status' => $builtSet['status'],
                'total_questions' => $builtSet['total_questions'],
                'is_playable' => $builtSet['is_playable'],
                'point_distribution' => json_encode($builtSet['point_distribution'], JSON_THROW_ON_ERROR),
                'lesson_coverage' => json_encode($builtSet['lesson_coverage'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($builtSet['questions'] !== []) {
                DB::table('curriculum_review_set_questions')->insert(array_map(fn ($question) => [
                    'id' => (string) Str::uuid(),
                    'review_set_id' => $reviewSetId,
                    'generated_question_id' => $question['generated_question_id'],
                    'question_id' => $question['question_id'],
                    'position' => $question['position'],
                    'points_value' => $question['points_value'],
                    'lesson_key' => $question['lesson_key'],
                    'created_at' => now(),
                ], $builtSet['questions']));
            }
        }

        return ['sets' => $builtSets, 'summary' => $summary];
    }

    /**
     * @param  array<string, mixed>  $jobPayload
     * @return array<string, mixed>
     */
    private function generateQuestionsForUnit(
        Textbook $textbook,
        string $unitKey,
        string $unitTitle,
        array $jobPayload,
        ?string $actorUserId,
    ): array {
        $tierTargets = CurriculumConfig::unitPointTierTargets();
        $lessonKeys = collect($this->extractUnitsFromStructure($textbook->approved_structure))
            ->firstWhere('unit_key', $unitKey)['lesson_keys'] ?? [];

        $this->upsertUnitGenerationStatus($textbook->id, $unitKey, [
            'unit_title' => $unitTitle,
            'status' => 'generating',
            'generated_count' => 0,
            'validated_count' => 0,
            'approved_count' => 0,
        ]);

        $chunks = DB::table('textbook_content_chunks')
            ->where('textbook_id', $textbook->id)
            ->where('unit_key', $unitKey)
            ->orderBy('source_page_start')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        if ($chunks === []) {
            throw new ValidationException(
                "No content chunks found for unit {$unitKey}. Approve units and ensure build_chunks completed."
            );
        }

        $sourceChars = array_sum(array_map(
            fn (array $chunk) => mb_strlen((string) ($chunk['content'] ?? '')),
            $chunks
        ));

        if ($sourceChars < 50) {
            throw new ValidationException('Unit content is unavailable for AI generation.');
        }

        $existingGenerated = DB::table('ai_generated_questions')
            ->where('textbook_id', $textbook->id)
            ->where('unit_key', $unitKey)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $accepted = array_values(array_filter(
            $existingGenerated,
            fn ($row) => ($row['validation_status'] ?? '') !== 'rejected'
        ));

        $existingPool = array_map(fn ($row) => [
            'question_text' => $row['question_text'],
            'answer_text' => $row['answer_text'],
        ], $accepted);

        $generatedCount = count($accepted);
        $validatedCount = count(array_filter(
            $accepted,
            fn ($row) => in_array($row['validation_status'], ['validated', 'needs_review', 'approved'], true)
        ));
        $approvedCount = count(array_filter($accepted, fn ($row) => ($row['validation_status'] ?? '') === 'approved'));

        $maxAttempts = (int) config('curriculum.target_questions_per_unit', 60)
            * (int) config('curriculum.max_generation_attempts_multiplier', 3);

        $attempts = 0;

        while ($validatedCount < (int) config('curriculum.target_questions_per_unit', 60) && $attempts < $maxAttempts) {
            $attempts++;

            foreach (config('curriculum.point_values', []) as $pointsValue) {
                $tierTarget = $tierTargets[$pointsValue];
                $tierCount = count(array_filter($accepted, fn ($row) => (int) $row['points_value'] === (int) $pointsValue));

                if ($tierCount >= $tierTarget) {
                    continue;
                }

                $slots = $this->allocateLessonSlots($lessonKeys, $tierTarget - $tierCount);

                foreach ($slots as $slot) {
                    if ($validatedCount >= (int) config('curriculum.target_questions_per_unit', 60)) {
                        break 2;
                    }

                    $lessonChunks = $slot['lesson_key']
                        ? array_values(array_filter($chunks, fn ($chunk) => ($chunk['lesson_key'] ?? null) === $slot['lesson_key']))
                        : $chunks;

                    $sourceChunks = $lessonChunks !== []
                        ? [$lessonChunks[($attempts - 1) % count($lessonChunks)]]
                        : [$chunks[($attempts - 1) % count($chunks)]];

                    $sourceContent = implode("\n", array_column($sourceChunks, 'content'));

                    $generated = $this->generator->generate($sourceChunks, [
                        'questionType' => 'single_answer',
                        'difficulty' => 3,
                        'points' => $pointsValue,
                        'grade' => $textbook->grade,
                    ]);

                    if ($generated['question_text'] === '' || $generated['answer_text'] === '') {
                        continue;
                    }

                    $duplicate = $this->duplicates->findDuplicateInPool(
                        ['question_text' => $generated['question_text'], 'answer_text' => $generated['answer_text']],
                        $existingPool,
                        (float) config('curriculum.cross_set_duplicate_threshold', 0.8)
                    );

                    if ($duplicate['duplicate']) {
                        continue;
                    }

                    $validation = $this->validator->validate([
                        'questionText' => $generated['question_text'],
                        'answerText' => $generated['answer_text'],
                        'sourceContent' => $sourceContent,
                        'pointsValue' => $generated['points_value'],
                        'difficultyLevel' => 3,
                        'grade' => $textbook->grade,
                        'existingQuestions' => array_column($existingPool, 'question_text'),
                    ]);

                    if ($validation['validation_status'] === 'rejected') {
                        continue;
                    }

                    $resolved = $this->provenance->resolve($sourceChunks, [
                        'unit_key' => $unitKey,
                        'lesson_key' => $slot['lesson_key'] ?? null,
                    ]);
                    $sourceGrounding = array_merge(
                        $generated['source_grounding'] ?? [],
                        $resolved['provenance_metadata']
                    );

                    $id = (string) Str::uuid();
                    $inserted = [
                        'id' => $id,
                        'textbook_id' => $textbook->id,
                        'unit_key' => $resolved['unit_key'] ?? $unitKey,
                        'lesson_key' => $resolved['lesson_key'],
                        'source_page_start' => $resolved['source_page_start'] ?? $generated['source_page_start'],
                        'source_page_end' => $resolved['source_page_end'] ?? $generated['source_page_end'],
                        'source_chunk_ids' => $this->provenance->formatPgUuidArray($resolved['source_chunk_ids']),
                        'question_text' => $generated['question_text'],
                        'answer_text' => $generated['answer_text'],
                        'question_type' => $generated['question_type'],
                        'points_value' => $generated['points_value'],
                        'difficulty_level' => 3,
                        'validation_status' => $validation['validation_status'],
                        'confidence_score' => $validation['confidence_score'],
                        'validation_notes' => $validation['validation_notes'],
                        'generation_model' => $generated['generation_model'],
                        'source_grounding' => json_encode($sourceGrounding, JSON_THROW_ON_ERROR),
                        'created_by' => $actorUserId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    DB::table('ai_generated_questions')->insert($inserted);
                    $inserted['source_chunk_ids'] = $generated['source_chunk_ids'] ?? [];

                    $accepted[] = $inserted;
                    $existingPool[] = [
                        'question_text' => $inserted['question_text'],
                        'answer_text' => $inserted['answer_text'],
                    ];

                    $generatedCount++;

                    if (in_array($inserted['validation_status'], ['validated', 'needs_review', 'approved'], true)) {
                        $validatedCount++;
                    }

                    if (
                        ($jobPayload['auto_promote'] ?? true) !== false
                        && in_array($inserted['validation_status'], ['validated', 'needs_review'], true)
                    ) {
                        $promoted = $this->promoteGeneratedQuestionToBank($inserted, $textbook, $unitTitle, $actorUserId);

                        if ($promoted) {
                            $inserted['question_id'] = $promoted['question_id'];
                            $inserted['validation_status'] = 'approved';
                            $approvedCount++;
                        }
                    } elseif ($inserted['validation_status'] === 'approved') {
                        $approvedCount++;
                    }
                }
            }
        }

        $this->upsertUnitGenerationStatus($textbook->id, $unitKey, [
            'status' => 'building_sets',
            'generated_count' => $generatedCount,
            'validated_count' => $validatedCount,
            'approved_count' => $approvedCount,
        ]);

        $builtSets = $this->persistReviewSetsForUnit($textbook->id, $unitKey, $unitTitle, $accepted);

        $this->upsertUnitGenerationStatus($textbook->id, $unitKey, [
            'status' => 'completed',
            'review_sets_total' => $builtSets['summary']['total_sets'],
            'review_sets_playable' => $builtSets['summary']['playable_sets'],
            'review_sets_incomplete' => $builtSets['summary']['incomplete_sets'],
            'metadata' => json_encode([
                'generation_attempts' => $attempts,
                'lesson_keys' => $lessonKeys,
            ], JSON_THROW_ON_ERROR),
        ]);

        return [
            'unit_key' => $unitKey,
            'generated_count' => $generatedCount,
            'validated_count' => $validatedCount,
            'approved_count' => $approvedCount,
            'review_sets' => $builtSets['summary'],
        ];
    }

    /**
     * @param  array<string, mixed>  $generated
     * @return array<string, mixed>|null
     */
    private function promoteGeneratedQuestionToBank(array $generated, Textbook $textbook, string $unitTitle, ?string $actorUserId): ?array
    {
        if (! $textbook->subject_id || ! $actorUserId) {
            return null;
        }

        try {
            $chapterResolution = $this->chapterMapping->resolveChapterForAiQuestion(
                $textbook->subject_id,
                $unitTitle,
                null,
                false,
                $actorUserId
            );

            if (! $chapterResolution['chapter_id']) {
                return null;
            }

            $question = $this->questions->create([
                'challenge_type' => 'school',
                'question_text' => $generated['question_text'],
                'answer_text' => $generated['answer_text'],
                'question_type' => QuestionTypeMapper::toBankType((string) $generated['question_type']),
                'points_value' => $generated['points_value'],
                'difficulty_level' => $generated['difficulty_level'],
                'subject_id' => $textbook->subject_id,
                'educational_stage' => $textbook->academic_stage,
                'grade' => QuestionGradeMapper::toBankGrade($textbook->grade),
                'chapter_id' => $chapterResolution['chapter_id'],
                'approval_status' => 'approved',
                'question_source' => 'textbook_ai',
                'ai_generated' => true,
                'textbook_id' => $textbook->id,
            ], ['actorUserId' => $actorUserId, 'actorRole' => 'admin']);

            DB::table('ai_generated_questions')
                ->where('id', $generated['id'])
                ->update([
                    'validation_status' => 'approved',
                    'question_id' => $question['id'] ?? null,
                    'updated_at' => now(),
                ]);

            return ['question_id' => $question['id'] ?? null];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function upsertUnitGenerationStatus(string $textbookId, string $unitKey, array $patch): void
    {
        $existing = DB::table('curriculum_unit_generation_status')
            ->where('textbook_id', $textbookId)
            ->where('unit_key', $unitKey)
            ->first();

        $payload = array_merge($patch, [
            'textbook_id' => $textbookId,
            'unit_key' => $unitKey,
            'updated_at' => now(),
        ]);

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $payload['metadata'] = json_encode($payload['metadata'], JSON_THROW_ON_ERROR);
        }

        if ($existing) {
            DB::table('curriculum_unit_generation_status')
                ->where('id', $existing->id)
                ->update($payload);

            return;
        }

        DB::table('curriculum_unit_generation_status')->insert(array_merge($payload, [
            'id' => (string) Str::uuid(),
            'target_questions' => config('curriculum.target_questions_per_unit', 60),
            'created_at' => now(),
        ]));
    }
}
