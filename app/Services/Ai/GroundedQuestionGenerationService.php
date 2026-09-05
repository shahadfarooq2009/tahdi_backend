<?php

namespace App\Services\Ai;

use App\Support\QuestionConstants;

class GroundedQuestionGenerationService
{
    public function __construct(
        private readonly AiClient $ai,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function generate(array $chunks, array $options): array
    {
        if ($chunks === []) {
            throw new \RuntimeException('No source chunks available for grounded generation');
        }

        $questionType = in_array($options['questionType'] ?? null, QuestionConstants::TYPES, true)
            ? $options['questionType']
            : 'single_answer';

        $points = in_array((int) ($options['points'] ?? 0), QuestionConstants::POINT_VALUES, true)
            ? (int) $options['points']
            : 100;

        if (! $this->ai->isConfigured()) {
            $firstChunk = $chunks[0];

            return [
                'question_text' => 'سؤال من '.($firstChunk['lesson_title'] ?? 'الدرس').' (صفحة '.($firstChunk['source_page_start'] ?? 1).')',
                'answer_text' => 'إجابة مستخرجة من المصدر',
                'question_type' => $questionType,
                'points_value' => $points,
                'confidence_score' => 0.4,
                'generation_model' => 'mock',
                'source_grounding' => ['excerpt' => mb_substr((string) ($firstChunk['content'] ?? ''), 0, 200)],
                'source_chunk_ids' => array_values(array_filter(array_column($chunks, 'id'))),
                'source_page_start' => $firstChunk['source_page_start'] ?? 1,
                'source_page_end' => $firstChunk['source_page_end'] ?? ($firstChunk['source_page_start'] ?? 1),
            ];
        }

        $prompt = $this->buildGroundedPrompt($chunks, [
            'questionType' => $questionType,
            'difficulty' => $options['difficulty'] ?? 3,
            'points' => $points,
            'grade' => $options['grade'] ?? null,
        ]);

        $content = $this->ai->chat([
            [
                'role' => 'system',
                'content' => 'أنت منشئ أسئلة تعليمية عربية. يجب أن تكون الإجابة مدعومة بالنص المصدر فقط.',
            ],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'model' => $this->ai->generationModel(),
            'temperature' => 0.4,
            'json' => true,
        ]);

        if ($content === '') {
            throw new \RuntimeException('AI generation returned empty content');
        }

        $parsed = $this->parseJsonFromAi($content);
        $firstChunk = $chunks[0];
        $lastChunk = $chunks[array_key_last($chunks)];

        return [
            'question_text' => trim((string) ($parsed['question_text'] ?? '')),
            'answer_text' => trim((string) ($parsed['answer_text'] ?? '')),
            'question_type' => $questionType,
            'points_value' => $points,
            'confidence_score' => (float) ($parsed['confidence'] ?? 0.5),
            'generation_model' => $this->ai->generationModel(),
            'source_grounding' => ['excerpt' => trim((string) ($parsed['source_excerpt'] ?? ''))],
            'source_chunk_ids' => array_values(array_filter(array_column($chunks, 'id'))),
            'source_page_start' => $firstChunk['source_page_start'] ?? 1,
            'source_page_end' => $lastChunk['source_page_end'] ?? ($firstChunk['source_page_end'] ?? 1),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     * @param  array<string, mixed>  $options
     */
    public function buildGroundedPrompt(array $chunks, array $options): string
    {
        $sourceBlock = collect($chunks)
            ->map(fn ($chunk, $index) => '[مصدر '.($index + 1).' | صفحات '.($chunk['source_page_start'] ?? '?').'-'.($chunk['source_page_end'] ?? '?')."]\n".($chunk['content'] ?? ''))
            ->implode("\n\n");

        return "أنشئ سؤالاً تعليمياً باللغة العربية اعتماداً حصرياً على المصادر التالية فقط.
لا تستخدم أي معلومة خارج النص.

المصادر:
{$sourceBlock}

المتطلبات:
- نوع السؤال: {$options['questionType']}
- مستوى الصعوبة (1-5): {$options['difficulty']}
- النقاط: {$options['points']}
- الصف/المرحلة: ".($options['grade'] ?? 'غير محدد').'

أعد JSON فقط:
{
  "question_text": "...",
  "answer_text": "...",
  "source_excerpt": "مقتطف يدعم الإجابة من المصدر",
  "confidence": 0.0
}';
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonFromAi(string $content): array
    {
        $trimmed = trim($content);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $trimmed, $matches)) {
            $trimmed = trim($matches[1]);
        }

        return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
    }
}
