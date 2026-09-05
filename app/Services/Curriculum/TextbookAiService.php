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
use App\Support\QuestionGradeMapper;
use App\Support\QuestionTypeMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TextbookAiService
{
    public function __construct(
        private readonly TextbookService $textbooks,
        private readonly TextbookJobService $jobs,
        private readonly GroundedQuestionGenerationService $generator,
        private readonly QuestionValidationService $validator,
        private readonly ChapterMappingService $chapterMapping,
        private readonly QuestionService $questions,
        private readonly ChunkProvenanceResolver $provenance,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function requestQuestionGeneration(string $textbookId, array $payload, array $actor): array
    {
        if (! Roles::roleHasPermission($actor['actorRole'], 'canUseAI')) {
            throw new ForbiddenException();
        }

        $textbook = $this->textbooks->getOrFail($textbookId);

        if ($textbook->structure_status !== 'approved') {
            throw new ValidationException('Textbook structure must be approved before question generation');
        }

        $chunkCount = (int) DB::table('textbook_content_chunks')
            ->where('textbook_id', $textbookId)
            ->count();

        if ($chunkCount === 0) {
            throw new ValidationException(
                'No content chunks found for this textbook. Approve units and wait for build_chunks to finish.'
            );
        }

        $batchId = (string) Str::uuid();

        DB::table('ai_question_generation_batches')->insert([
            'id' => $batchId,
            'textbook_id' => $textbookId,
            'unit_key' => $payload['unit_key'] ?? null,
            'lesson_key' => $payload['lesson_key'] ?? null,
            'difficulty_level' => $payload['difficulty_level'] ?? 3,
            'points_value' => $payload['points_value'],
            'question_type' => $payload['question_type'] ?? 'single_answer',
            'requested_count' => $payload['count'] ?? 1,
            'status' => 'queued',
            'created_by' => $actor['actorUserId'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = $this->jobs->enqueue($textbookId, 'generate_questions', [
            'batch_id' => $batchId,
            'unit_key' => $payload['unit_key'] ?? null,
            'lesson_key' => $payload['lesson_key'] ?? null,
            'difficulty_level' => $payload['difficulty_level'] ?? 3,
            'points_value' => $payload['points_value'],
            'question_type' => $payload['question_type'] ?? 'single_answer',
            'count' => $payload['count'] ?? 1,
            'subject_id' => $textbook->subject_id,
            'grade' => $textbook->grade,
            'educational_stage' => $textbook->academic_stage,
        ], $actor['actorUserId']);

        $batch = DB::table('ai_question_generation_batches')->where('id', $batchId)->first();

        return ['batch' => $batch, 'job_id' => $job->id];
    }

    public function runGenerateQuestionsJob(TextbookProcessingJob $job): void
    {
        $payload = $job->payload ?? [];
        $textbook = $this->textbooks->getOrFail($job->textbook_id);

        $query = DB::table('textbook_content_chunks')
            ->where('textbook_id', $textbook->id)
            ->orderBy('source_page_start');

        if (! empty($payload['unit_key'])) {
            $query->where('unit_key', $payload['unit_key']);
        }

        if (! empty($payload['lesson_key'])) {
            $query->where('lesson_key', $payload['lesson_key']);
        }

        $chunks = $query->limit(12)->get()->map(fn ($row) => (array) $row)->all();

        if ($chunks === []) {
            throw new \RuntimeException('No content chunks found for the requested scope');
        }

        $sourceChars = array_sum(array_map(
            fn ($row) => mb_strlen((string) ($row->content ?? '')),
            $chunks->all()
        ));

        if ($sourceChars < 50) {
            throw new \RuntimeException('Unit content is unavailable for AI generation.');
        }

        $existingQuestions = DB::table('ai_generated_questions')
            ->where('textbook_id', $textbook->id)
            ->pluck('question_text')
            ->all();

        $count = (int) ($payload['count'] ?? 1);
        $generatedCount = 0;

        for ($index = 0; $index < $count; $index++) {
            $sourceChunks = [$chunks[$index % count($chunks)]];
            $sourceContent = implode("\n", array_column($sourceChunks, 'content'));

            $generated = $this->generator->generate($sourceChunks, [
                'questionType' => $payload['question_type'] ?? 'single_answer',
                'difficulty' => $payload['difficulty_level'] ?? 3,
                'points' => $payload['points_value'],
                'grade' => $payload['grade'] ?? $textbook->grade,
            ]);

            if ($generated['question_text'] === '' || $generated['answer_text'] === '') {
                continue;
            }

            $validation = $this->validator->validate([
                'questionText' => $generated['question_text'],
                'answerText' => $generated['answer_text'],
                'sourceContent' => $sourceContent,
                'pointsValue' => $generated['points_value'],
                'difficultyLevel' => $payload['difficulty_level'] ?? 3,
                'grade' => $payload['grade'] ?? $textbook->grade,
                'existingQuestions' => $existingQuestions,
            ]);

            $provenance = $this->provenance->resolve($sourceChunks, [
                'unit_key' => $payload['unit_key'] ?? null,
                'lesson_key' => $payload['lesson_key'] ?? null,
            ]);
            $sourceGrounding = array_merge(
                $generated['source_grounding'] ?? [],
                $provenance['provenance_metadata']
            );

            DB::table('ai_generated_questions')->insert([
                'id' => (string) Str::uuid(),
                'batch_id' => $payload['batch_id'] ?? null,
                'textbook_id' => $textbook->id,
                'unit_key' => $provenance['unit_key'],
                'lesson_key' => $provenance['lesson_key'],
                'source_page_start' => $provenance['source_page_start'] ?? $generated['source_page_start'],
                'source_page_end' => $provenance['source_page_end'] ?? $generated['source_page_end'],
                'source_chunk_ids' => $this->provenance->formatPgUuidArray($provenance['source_chunk_ids']),
                'question_text' => $generated['question_text'],
                'answer_text' => $generated['answer_text'],
                'question_type' => $generated['question_type'],
                'points_value' => $generated['points_value'],
                'difficulty_level' => $payload['difficulty_level'] ?? 3,
                'validation_status' => $validation['validation_status'],
                'confidence_score' => $validation['confidence_score'],
                'validation_notes' => $validation['validation_notes'],
                'generation_model' => $generated['generation_model'],
                'source_grounding' => json_encode($sourceGrounding, JSON_THROW_ON_ERROR),
                'created_by' => $job->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $existingQuestions[] = $generated['question_text'];
            $generatedCount++;
            $this->jobs->updateProgress($job->id, (int) round((($index + 1) / $count) * 100));
        }

        if (! empty($payload['batch_id'])) {
            DB::table('ai_question_generation_batches')
                ->where('id', $payload['batch_id'])
                ->update([
                    'generated_count' => $generatedCount,
                    'status' => $generatedCount > 0 ? 'needs_review' : 'failed',
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, object>
     */
    public function listGeneratedQuestions(string $textbookId, array $filters = []): array
    {
        $this->textbooks->getOrFail($textbookId);

        $query = DB::table('ai_generated_questions')
            ->where('textbook_id', $textbookId)
            ->orderByDesc('created_at');

        if (! empty($filters['validation_status'])) {
            $query->where('validation_status', $filters['validation_status']);
        }

        return $query->get()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getGeneratedQuestionProvenance(string $textbookId, string $generatedQuestionId): array
    {
        $this->textbooks->getOrFail($textbookId);

        $generated = DB::table('ai_generated_questions')
            ->where('id', $generatedQuestionId)
            ->where('textbook_id', $textbookId)
            ->first();

        if (! $generated) {
            throw new NotFoundException('Generated question not found');
        }

        $chunkIds = $this->parsePgUuidArray($generated->source_chunk_ids ?? '{}');
        $chunks = [];

        if ($chunkIds !== []) {
            $chunks = DB::table('textbook_content_chunks')
                ->whereIn('id', $chunkIds)
                ->get(['id', 'unit_title', 'lesson_title', 'source_page_start', 'source_page_end', 'content'])
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        $sourceContent = implode("\n\n", array_column($chunks, 'content'));
        $answerSnippet = mb_substr((string) $generated->answer_text, 0, min(20, mb_strlen((string) $generated->answer_text)));

        $sourceGrounding = json_decode((string) ($generated->source_grounding ?? '{}'), true);
        $lessonKeys = is_array($sourceGrounding['lesson_keys'] ?? null)
            ? $sourceGrounding['lesson_keys']
            : array_values(array_filter([(string) ($generated->lesson_key ?? '')]));

        return [
            'generated_question' => $generated,
            'source_chunks' => $chunks,
            'provenance' => [
                'textbook_id' => $generated->textbook_id,
                'unit_key' => $generated->unit_key,
                'lesson_key' => $generated->lesson_key,
                'lesson_keys' => $lessonKeys,
                'source_page_start' => $generated->source_page_start,
                'source_page_end' => $generated->source_page_end,
                'source_chunk_ids' => $chunkIds,
                'answer_supported_by_source' => $answerSnippet !== '' && str_contains($sourceContent, $answerSnippet),
                'confidence_score' => $generated->confidence_score,
                'validation_status' => $generated->validation_status,
            ],
        ];
    }

    /**
     * @return object
     */
    public function reviewGeneratedQuestion(
        string $generatedQuestionId,
        string $decision,
        array $actor,
        ?string $chapterId = null,
        bool $createChapter = false,
    ): object {
        $generated = DB::table('ai_generated_questions')->where('id', $generatedQuestionId)->first();

        if (! $generated) {
            throw new NotFoundException('Generated question not found');
        }

        if ($decision === 'rejected') {
            DB::table('ai_generated_questions')
                ->where('id', $generatedQuestionId)
                ->update(['validation_status' => 'rejected', 'updated_at' => now()]);

            return DB::table('ai_generated_questions')->where('id', $generatedQuestionId)->first();
        }

        $generated = $this->ensureGeneratedQuestionProvenance($generated);
        $textbook = $this->textbooks->getOrFail($generated->textbook_id);
        $unitTitle = $this->findNodeTitle($textbook, $generated->unit_key) ?? 'وحدة الكتاب';

        $chapterResolution = $this->chapterMapping->resolveChapterForAiQuestion(
            (string) $textbook->subject_id,
            $unitTitle,
            $chapterId,
            $createChapter,
            $actor['actorUserId']
        );

        $question = $this->questions->create([
            'challenge_type' => 'school',
            'question_text' => $generated->question_text,
            'answer_text' => $generated->answer_text,
            'question_type' => QuestionTypeMapper::toBankType((string) $generated->question_type),
            'points_value' => $generated->points_value,
            'difficulty_level' => $generated->difficulty_level,
            'subject_id' => $textbook->subject_id,
            'educational_stage' => $textbook->academic_stage,
            'grade' => QuestionGradeMapper::toBankGrade($textbook->grade),
            'chapter_id' => $chapterResolution['chapter_id'],
            'approval_status' => 'pending',
            'question_source' => 'textbook_ai',
            'ai_generated' => true,
            'textbook_id' => $textbook->id,
        ], $actor);

        DB::table('ai_generated_questions')
            ->where('id', $generatedQuestionId)
            ->update([
                'validation_status' => 'approved',
                'question_id' => $question['id'] ?? null,
                'updated_at' => now(),
            ]);

        return DB::table('ai_generated_questions')->where('id', $generatedQuestionId)->first();
    }

    /**
     * @param  string[]  $ids
     * @return array<int, object>
     */
    public function bulkReviewGeneratedQuestions(
        array $ids,
        string $decision,
        array $actor,
        ?string $chapterId = null,
        bool $createChapter = false,
    ): array {
        $results = [];

        foreach ($ids as $id) {
            $results[] = $this->reviewGeneratedQuestion($id, $decision, $actor, $chapterId, $createChapter);
        }

        return $results;
    }

    private function ensureGeneratedQuestionProvenance(object $generated): object
    {
        if (filled($generated->lesson_key ?? null)) {
            return $generated;
        }

        $chunkIds = $this->parsePgUuidArray($generated->source_chunk_ids ?? '{}');
        if ($chunkIds === []) {
            return $generated;
        }

        $chunks = DB::table('textbook_content_chunks')
            ->whereIn('id', $chunkIds)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $resolved = $this->provenance->resolve($chunks, [
            'unit_key' => $generated->unit_key,
            'lesson_key' => $generated->lesson_key,
        ]);

        if ($resolved['lesson_key'] === null && $resolved['provenance_metadata'] === []) {
            return $generated;
        }

        $sourceGrounding = json_decode((string) ($generated->source_grounding ?? '{}'), true);
        if (! is_array($sourceGrounding)) {
            $sourceGrounding = [];
        }

        DB::table('ai_generated_questions')
            ->where('id', $generated->id)
            ->update([
                'unit_key' => $resolved['unit_key'] ?? $generated->unit_key,
                'lesson_key' => $resolved['lesson_key'],
                'source_page_start' => $resolved['source_page_start'] ?? $generated->source_page_start,
                'source_page_end' => $resolved['source_page_end'] ?? $generated->source_page_end,
                'source_grounding' => json_encode(
                    array_merge($sourceGrounding, $resolved['provenance_metadata']),
                    JSON_THROW_ON_ERROR
                ),
                'updated_at' => now(),
            ]);

        return DB::table('ai_generated_questions')->where('id', $generated->id)->first();
    }

    private function findNodeTitle(Textbook $textbook, ?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        $structure = $textbook->approved_structure ?? $textbook->proposed_structure;
        $title = null;

        if (is_array($structure)) {
            app(ChunkingService::class)->walkStructure($structure, function (array $node) use ($key, &$title): void {
                if (($node['key'] ?? null) === $key) {
                    $title = $node['title'] ?? null;
                }
            });
        }

        return is_string($title) ? $title : null;
    }

    /**
     * @return string[]
     */
    private function parsePgUuidArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value));
        }

        $stringValue = trim((string) $value, '{}');

        if ($stringValue === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $stringValue))));
    }
}
