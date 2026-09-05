<?php

namespace App\Console\Commands;

use App\Models\Textbook;
use App\Services\Ai\GroundedQuestionGenerationService;
use App\Services\Ai\QuestionValidationService;
use App\Services\Curriculum\TextbookUnitDiagnosticService;
use App\Support\ChunkProvenanceResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestUnitGenerationCommand extends Command
{
    protected $signature = 'unit:test-generation
        {unit : Unit key, curriculum_unit_generation_status id, or textbook_id:unit_key}
        {--textbook= : Textbook UUID when unit is a unit_key only}
        {--count=5 : Number of test questions to generate}
        {--save : Persist generated rows to ai_generated_questions}';

    protected $description = 'Bypass queue and test AI question generation for one unit';

    public function handle(
        TextbookUnitDiagnosticService $diagnostics,
        GroundedQuestionGenerationService $generator,
        QuestionValidationService $validator,
        ChunkProvenanceResolver $provenance,
    ): int {
        try {
            $resolved = $diagnostics->resolveUnitReference(
                (string) $this->argument('unit'),
                $this->option('textbook') ? (string) $this->option('textbook') : null,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $textbookId = $resolved['textbook_id'];
        $unitKey = $resolved['unit_key'];
        $count = max(1, (int) $this->option('count'));

        $textbook = Textbook::query()->find($textbookId);

        if (! $textbook) {
            $this->error('Textbook not found: '.$textbookId);

            return self::FAILURE;
        }

        try {
            $contentChars = $diagnostics->assertUnitContentAvailable($textbookId, $unitKey);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $chunks = $diagnostics->loadUnitChunks($textbookId, $unitKey);

        $this->info("Unit {$unitKey} has {$contentChars} content characters across ".count($chunks).' chunk(s).');
        $this->info('AI provider: '.app(\App\Services\Ai\AiClient::class)->provider().' | configured: '
            .(app(\App\Services\Ai\AiClient::class)->isConfigured() ? 'yes' : 'no'));

        $results = [];
        $failures = [];

        for ($index = 0; $index < $count; $index++) {
            $sourceChunks = [$chunks[$index % count($chunks)]];
            $sourceContent = implode("\n", array_column($sourceChunks, 'content'));

            try {
                $generated = $generator->generate($sourceChunks, [
                    'questionType' => 'single_answer',
                    'difficulty' => 3,
                    'points' => 100,
                    'grade' => $textbook->grade,
                ]);

                $validation = $validator->validate([
                    'questionText' => $generated['question_text'],
                    'answerText' => $generated['answer_text'],
                    'sourceContent' => $sourceContent,
                    'pointsValue' => $generated['points_value'],
                    'difficultyLevel' => 3,
                    'grade' => $textbook->grade,
                    'existingQuestions' => array_column($results, 'question_text'),
                ]);

                $row = [
                    'index' => $index + 1,
                    'question_text' => $generated['question_text'],
                    'answer_text' => $generated['answer_text'],
                    'question_type' => $generated['question_type'],
                    'points_value' => $generated['points_value'],
                    'generation_model' => $generated['generation_model'],
                    'validation_status' => $validation['validation_status'],
                    'confidence_score' => $validation['confidence_score'],
                    'validation_notes' => $validation['validation_notes'],
                    'source_page_start' => $generated['source_page_start'] ?? null,
                    'source_page_end' => $generated['source_page_end'] ?? null,
                ];

                $results[] = $row;

                if ($this->option('save') && $validation['validation_status'] !== 'rejected') {
                    $resolvedProv = $provenance->resolve($sourceChunks, ['unit_key' => $unitKey]);
                    DB::table('ai_generated_questions')->insert([
                        'id' => (string) Str::uuid(),
                        'textbook_id' => $textbookId,
                        'unit_key' => $resolvedProv['unit_key'] ?? $unitKey,
                        'lesson_key' => $resolvedProv['lesson_key'] ?? null,
                        'source_page_start' => $resolvedProv['source_page_start'] ?? $generated['source_page_start'],
                        'source_page_end' => $resolvedProv['source_page_end'] ?? $generated['source_page_end'],
                        'source_chunk_ids' => $provenance->formatPgUuidArray($resolvedProv['source_chunk_ids']),
                        'question_text' => $generated['question_text'],
                        'answer_text' => $generated['answer_text'],
                        'question_type' => $generated['question_type'],
                        'points_value' => $generated['points_value'],
                        'difficulty_level' => 3,
                        'validation_status' => $validation['validation_status'],
                        'confidence_score' => $validation['confidence_score'],
                        'validation_notes' => $validation['validation_notes'],
                        'generation_model' => $generated['generation_model'],
                        'source_grounding' => json_encode($generated['source_grounding'] ?? [], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            } catch (\Throwable $exception) {
                $failures[] = [
                    'index' => $index + 1,
                    'error' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $this->newLine();
        $this->line(json_encode([
            'textbook_id' => $textbookId,
            'unit_key' => $unitKey,
            'requested' => $count,
            'generated' => count($results),
            'failed' => count($failures),
            'saved' => $this->option('save'),
            'questions' => $results,
            'errors' => $failures,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($failures !== []) {
            $this->newLine();
            $this->warn('Some generations failed. See errors above.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Test generation completed successfully.');

        return self::SUCCESS;
    }
}
