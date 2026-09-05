<?php

namespace App\Services\Curriculum;

class DuplicateDetectionService
{
    /**
     * @param  array{question_text: string, answer_text?: string|null}  $candidate
     * @param  array{question_text: string, answer_text?: string|null}  $existing
     * @return array{duplicate: bool, reason?: string, score?: float}
     */
    public function areQuestionsDuplicates(array $candidate, array $existing, ?float $questionThreshold = null, ?float $answerThreshold = null): array
    {
        $questionThreshold ??= (float) config('curriculum.duplicate_similarity_threshold', 0.85);
        $answerThreshold ??= (float) config('curriculum.answer_similarity_threshold', 0.9);

        $questionScore = ArabicTextService::significantTokenSimilarity(
            $candidate['question_text'],
            $existing['question_text']
        );

        if ($questionScore >= $questionThreshold) {
            return ['duplicate' => true, 'reason' => 'question_similarity', 'score' => $questionScore];
        }

        if (! empty($candidate['answer_text']) && ! empty($existing['answer_text'])) {
            $answerScore = ArabicTextService::textSimilarity($candidate['answer_text'], $existing['answer_text']);
            $questionOverlap = ArabicTextService::significantTokenSimilarity(
                $candidate['question_text'],
                $existing['question_text']
            );

            if ($answerScore >= $answerThreshold) {
                if (
                    $answerScore >= 0.95
                    || $questionOverlap >= (float) config('curriculum.cross_set_duplicate_threshold', 0.8)
                    || $questionScore >= 0.5
                ) {
                    return ['duplicate' => true, 'reason' => 'same_answer_concept', 'score' => $answerScore];
                }
            }
        }

        return ['duplicate' => false, 'score' => $questionScore];
    }

    /**
     * @param  array{question_text: string, answer_text?: string|null}  $candidate
     * @param  array<int, array{question_text: string, answer_text?: string|null}>  $pool
     * @return array{duplicate: bool, match?: array<string, mixed>, reason?: string, score?: float}
     */
    public function findDuplicateInPool(array $candidate, array $pool, ?float $threshold = null): array
    {
        $threshold ??= (float) config('curriculum.duplicate_similarity_threshold', 0.85);

        foreach ($pool as $existing) {
            $result = $this->areQuestionsDuplicates($candidate, $existing, $threshold);

            if ($result['duplicate']) {
                return ['duplicate' => true, 'match' => $existing, ...$result];
            }
        }

        return ['duplicate' => false];
    }
}
