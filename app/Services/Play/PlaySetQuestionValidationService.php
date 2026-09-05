<?php

namespace App\Services\Play;

use App\Exceptions\ValidationException;

class PlaySetQuestionValidationService
{
    /**
     * @param  array<int, array{question?: string, answer?: string, points?: int}>  $questions
     */
    public function validate(array $questions): void
    {
        $expectedCount = (int) config('play_sets.question_count', 20);
        $allowedPoints = config('play_sets.allowed_points', [100, 200, 300, 400, 500]);
        $perTier = (int) config('play_sets.questions_per_point_tier', 4);

        if (count($questions) !== $expectedCount) {
            throw new ValidationException("يجب أن يحتوي الرد على {$expectedCount} سؤالاً بالضبط");
        }

        $pointCounts = array_fill_keys($allowedPoints, 0);
        $seenQuestions = [];

        foreach ($questions as $index => $row) {
            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));
            $points = (int) ($row['points'] ?? 0);

            if ($question === '' || $answer === '') {
                throw new ValidationException('كل سؤال يجب أن يحتوي على نص سؤال وإجابة غير فارغين');
            }

            if (! in_array($points, $allowedPoints, true)) {
                throw new ValidationException('قيمة النقاط غير مسموحة. المسموح فقط: 100، 200، 300، 400، 500');
            }

            $pointCounts[$points]++;

            $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $question) ?? $question);
            if (isset($seenQuestions[$normalized])) {
                throw new ValidationException('تم العثور على أسئلة مكررة أو متشابهة في الرد');
            }

            $seenQuestions[$normalized] = true;
        }

        foreach ($allowedPoints as $points) {
            if (($pointCounts[$points] ?? 0) !== $perTier) {
                throw new ValidationException("يجب أن يحتوي الرد على {$perTier} أسئلة بقيمة {$points} نقطة بالضبط");
            }
        }
    }

    /**
     * @param  array{question?: string, answer?: string, points?: int}  $question
     */
    public function validateSingle(array $question): void
    {
        $allowedPoints = config('play_sets.allowed_points', [100, 200, 300, 400, 500]);
        $questionText = trim((string) ($question['question'] ?? ''));
        $answerText = trim((string) ($question['answer'] ?? ''));
        $points = (int) ($question['points'] ?? 0);

        if ($questionText === '' || $answerText === '') {
            throw new ValidationException('يجب أن يحتوي السؤال على نص سؤال وإجابة غير فارغين');
        }

        if (! in_array($points, $allowedPoints, true)) {
            throw new ValidationException('قيمة النقاط غير مسموحة. المسموح فقط: 100، 200، 300، 400، 500');
        }
    }
}
