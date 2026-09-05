<?php

namespace App\Services\Ai;

use App\Services\Curriculum\DuplicateDetectionService;
use App\Support\QuestionConstants;

class QuestionValidationService
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly DuplicateDetectionService $duplicates,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{validation_status: string, confidence_score: float, validation_notes: string, checks: array<string, bool>}
     */
    public function validate(array $input): array
    {
        $duplicate = $this->duplicates->findDuplicateInPool(
            ['question_text' => $input['questionText'], 'answer_text' => $input['answerText'] ?? null],
            array_map(fn ($text) => ['question_text' => $text], $input['existingQuestions'] ?? [])
        );

        if ($duplicate['duplicate']) {
            return [
                'validation_status' => 'rejected',
                'confidence_score' => 0.1,
                'validation_notes' => 'سؤال مكرر أو مشابه جداً لسؤال موجود',
                'checks' => ['duplicate' => false],
            ];
        }

        if (! in_array((int) ($input['pointsValue'] ?? 0), QuestionConstants::POINT_VALUES, true)) {
            return [
                'validation_status' => 'rejected',
                'confidence_score' => 0.0,
                'validation_notes' => 'قيمة النقاط غير مدعومة',
                'checks' => ['points' => false],
            ];
        }

        $sourceContent = trim((string) ($input['sourceContent'] ?? ''));

        if ($sourceContent === '') {
            return [
                'validation_status' => 'rejected',
                'confidence_score' => 0.0,
                'validation_notes' => 'لا يوجد مصدر نصي لدعم السؤال',
                'checks' => ['source' => false],
            ];
        }

        if (! $this->ai->isConfigured()) {
            $answerSnippet = mb_substr((string) $input['answerText'], 0, 20);
            $supported = $answerSnippet !== '' && str_contains($sourceContent, $answerSnippet);

            return [
                'validation_status' => $supported ? 'needs_review' : 'rejected',
                'confidence_score' => $supported ? 0.55 : 0.2,
                'validation_notes' => $supported
                    ? 'تم التحقق الأولي بدون مزود AI'
                    : 'الإجابة غير مدعومة بالمصدر (تحقق أولي)',
                'checks' => [
                    'source' => $supported,
                    'understandable' => true,
                    'difficulty' => true,
                    'wording' => true,
                ],
            ];
        }

        $content = $this->ai->chat([
            [
                'role' => 'system',
                'content' => 'أنت مدقق أسئلة تعليمية. تحقق من أن الإجابة مدعومة بالمصدر وأن السؤال مناسب للصف.',
            ],
            [
                'role' => 'user',
                'content' => 'المصدر:
'.mb_substr($sourceContent, 0, 4000).'

السؤال:
'.$input['questionText'].'

الإجابة:
'.$input['answerText'].'

النقاط: '.$input['pointsValue'].'
الصعوبة: '.($input['difficultyLevel'] ?? 3).'
الصف: '.($input['grade'] ?? 'غير محدد').'

أعد JSON:
{
  "answer_supported": true,
  "in_curriculum": true,
  "understandable": true,
  "difficulty_ok": true,
  "points_ok": true,
  "arabic_wording_ok": true,
  "confidence": 0.0,
  "notes": "..."
}',
            ],
        ], [
            'model' => $this->ai->validationModel(),
            'temperature' => 0.1,
            'json' => true,
        ]);

        if ($content === '') {
            return [
                'validation_status' => 'needs_review',
                'confidence_score' => 0.3,
                'validation_notes' => 'تعذر التحقق عبر AI',
                'checks' => [],
            ];
        }

        $parsed = $this->parseJsonFromAi($content);
        $checks = [
            'source' => (bool) ($parsed['answer_supported'] ?? false),
            'curriculum' => (bool) ($parsed['in_curriculum'] ?? false),
            'understandable' => (bool) ($parsed['understandable'] ?? false),
            'difficulty' => (bool) ($parsed['difficulty_ok'] ?? false),
            'points' => (bool) ($parsed['points_ok'] ?? false),
            'wording' => (bool) ($parsed['arabic_wording_ok'] ?? false),
        ];

        $allPassed = ! in_array(false, $checks, true);
        $confidence = (float) ($parsed['confidence'] ?? ($allPassed ? 0.8 : 0.35));

        $validationStatus = 'needs_review';

        if (! $checks['source'] || ! $checks['curriculum']) {
            $validationStatus = 'rejected';
        } elseif ($allPassed && $confidence >= 0.75) {
            $validationStatus = 'validated';
        }

        return [
            'validation_status' => $validationStatus,
            'confidence_score' => $confidence,
            'validation_notes' => trim((string) ($parsed['notes'] ?? '')),
            'checks' => $checks,
        ];
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
