<?php

namespace App\Services\Curriculum;

use App\Support\CurriculumConfig;
use App\Support\QuestionConstants;

class ReviewSetBuilderService
{
    private const ACCEPTABLE_STATUSES = ['validated', 'needs_review', 'approved'];

    public function __construct(
        private readonly DuplicateDetectionService $duplicates,
    ) {}

    public function isReviewSetPlayable(int $count): bool
    {
        return $count >= (int) config('curriculum.min_playable_review_set_size', 15);
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<string, int>
     */
    public function computePointDistribution(array $questions): array
    {
        $distribution = [];

        foreach ($questions as $question) {
            $key = (string) $question['points_value'];
            $distribution[$key] = ($distribution[$key] ?? 0) + 1;
        }

        return $distribution;
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<string, int>
     */
    public function computeLessonCoverage(array $questions): array
    {
        $coverage = [];

        foreach ($questions as $question) {
            $lessonKey = $question['lesson_key'] ?? 'unknown';
            $coverage[$lessonKey] = ($coverage[$lessonKey] ?? 0) + 1;
        }

        return $coverage;
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @param  array{lessonKeys?: string[]}  $options
     * @return array<int, array<string, mixed>>
     */
    public function buildReviewSetsFromQuestions(array $questions, array $options = []): array
    {
        $eligible = array_values(array_filter(
            $questions,
            fn ($question) => ! empty($question['id'])
                && ! empty($question['question_text'])
                && in_array($question['validation_status'] ?? 'validated', self::ACCEPTABLE_STATUSES, true)
                && in_array((int) $question['points_value'], QuestionConstants::POINT_VALUES, true)
        ));

        $lessonKeys = $options['lessonKeys']
            ?? array_values(array_unique(array_filter(array_column($eligible, 'lesson_key'))));

        $reviewSets = [];
        $assigned = [];
        $sequenceNumber = 1;

        while ($this->canBuildFullBalancedSet($eligible, $assigned)) {
            $currentSet = $this->buildSingleReviewSet($eligible, $assigned, $lessonKeys, true);

            if ($currentSet === null) {
                break;
            }

            $assigned = [...$assigned, ...$currentSet];
            $reviewSets[] = $this->createBuiltReviewSet($currentSet, $sequenceNumber, true);
            $sequenceNumber++;
        }

        $usedIds = array_flip(array_column($assigned, 'id'));
        $remaining = array_values(array_filter($eligible, fn ($q) => ! isset($usedIds[$q['id']])));

        if ($remaining !== []) {
            $tailSet = $this->buildSingleReviewSet($eligible, $assigned, $lessonKeys, false);

            if ($tailSet !== null && $tailSet !== []) {
                $playable = $this->isReviewSetPlayable(count($tailSet));
                $reviewSets[] = $this->createBuiltReviewSet($tailSet, $sequenceNumber, $playable);
            }
        }

        return $reviewSets;
    }

    /**
     * @param  array<int, array<string, mixed>>  $reviewSets
     * @return array{total_sets: int, playable_sets: int, incomplete_sets: int, total_questions: int}
     */
    public function summarizeReviewSets(array $reviewSets): array
    {
        $playable = count(array_filter($reviewSets, fn ($set) => $set['is_playable'] ?? false));
        $incomplete = count($reviewSets) - $playable;
        $totalQuestions = array_sum(array_column($reviewSets, 'total_questions'));

        return [
            'total_sets' => count($reviewSets),
            'playable_sets' => $playable,
            'incomplete_sets' => $incomplete,
            'total_questions' => $totalQuestions,
        ];
    }

    /**
     * @param  array<string, int>  $distribution
     */
    public function isBalancedPointDistribution(array $distribution): bool
    {
        foreach (CurriculumConfig::reviewSetPointTargets() as $points => $needed) {
            if (($distribution[(string) $points] ?? 0) !== $needed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $eligible
     * @param  array<int, array<string, mixed>>  $assigned
     */
    private function canBuildFullBalancedSet(array $eligible, array $assigned): bool
    {
        $usedIds = array_flip(array_column($assigned, 'id'));
        $remaining = array_values(array_filter($eligible, fn ($q) => ! isset($usedIds[$q['id']])));

        foreach (CurriculumConfig::reviewSetPointTargets() as $points => $needed) {
            $available = count(array_filter(
                $remaining,
                fn ($question) => (int) $question['points_value'] === (int) $points
            ));

            if ($available < $needed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $eligible
     * @param  array<int, array<string, mixed>>  $assigned
     * @param  string[]  $lessonKeys
     * @return array<int, array<string, mixed>>|null
     */
    private function buildSingleReviewSet(array $eligible, array $assigned, array $lessonKeys, bool $requireFullBalancedSet): ?array
    {
        $currentSet = [];
        $lessonCountsInSet = [];

        foreach (CurriculumConfig::reviewSetPointTargets() as $points => $needed) {
            for ($slot = 0; $slot < $needed; $slot++) {
                $picked = $this->pickQuestionForTier(
                    $eligible,
                    [...$assigned, ...$currentSet],
                    (int) $points,
                    $lessonCountsInSet,
                    $lessonKeys
                );

                if ($picked === null) {
                    if ($requireFullBalancedSet) {
                        return null;
                    }

                    continue;
                }

                $currentSet[] = $picked;
                $lessonKey = $picked['lesson_key'] ?? 'unknown';
                $lessonCountsInSet[$lessonKey] = ($lessonCountsInSet[$lessonKey] ?? 0) + 1;
            }
        }

        if ($requireFullBalancedSet) {
            $balanced = $this->isBalancedPointDistribution($this->computePointDistribution($currentSet));

            if (! $balanced || count($currentSet) !== (int) config('curriculum.questions_per_review_set', 20)) {
                return null;
            }
        }

        return $currentSet !== [] ? $currentSet : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  array<int, array<string, mixed>>  $usedInPriorSets
     * @param  string[]  $lessonKeysInUnit
     * @return array<string, mixed>|null
     */
    private function pickQuestionForTier(
        array $candidates,
        array $usedInPriorSets,
        int $pointsValue,
        array $lessonCountsInSet,
        array $lessonKeysInUnit,
    ): ?array {
        $usedIds = array_flip(array_column($usedInPriorSets, 'id'));
        $tierCandidates = array_values(array_filter(
            $candidates,
            fn ($candidate) => (int) $candidate['points_value'] === $pointsValue && ! isset($usedIds[$candidate['id']])
        ));

        if ($tierCandidates === []) {
            return null;
        }

        $scored = [];

        foreach ($tierCandidates as $candidate) {
            $duplicate = $this->duplicates->findDuplicateInPool($candidate, $usedInPriorSets, (float) config('curriculum.duplicate_similarity_threshold', 0.85));

            if ($duplicate['duplicate']) {
                continue;
            }

            $scored[] = [
                'candidate' => $candidate,
                'score' => $this->scoreLessonCoverage($candidate, $lessonCountsInSet, $lessonKeysInUnit),
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scored[0]['candidate'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, int>  $lessonCountsInSet
     * @param  string[]  $lessonKeysInUnit
     */
    private function scoreLessonCoverage(array $candidate, array $lessonCountsInSet, array $lessonKeysInUnit): int
    {
        $lessonKey = $candidate['lesson_key'] ?? 'unknown';
        $currentCount = $lessonCountsInSet[$lessonKey] ?? 0;
        $maxInSet = $lessonCountsInSet === [] ? 0 : max($lessonCountsInSet);
        $diversityBonus = $maxInSet - $currentCount;

        if (count($lessonKeysInUnit) <= 1) {
            return $diversityBonus;
        }

        $lessonsRepresented = count(array_filter($lessonCountsInSet, fn ($count) => $count > 0));

        if ($lessonsRepresented < min(2, count($lessonKeysInUnit)) && $currentCount === 0) {
            return $diversityBonus + 10;
        }

        return $diversityBonus;
    }

    /**
     * @param  array<int, array<string, mixed>>  $currentSet
     * @return array<string, mixed>
     */
    private function createBuiltReviewSet(array $currentSet, int $sequenceNumber, bool $playable): array
    {
        return [
            'sequence_number' => $sequenceNumber,
            'status' => $playable ? 'playable' : 'incomplete',
            'is_playable' => $playable,
            'total_questions' => count($currentSet),
            'questions' => array_map(
                fn ($question, $index) => [
                    'generated_question_id' => $question['id'],
                    'question_id' => $question['question_id'] ?? null,
                    'position' => $index + 1,
                    'points_value' => (int) $question['points_value'],
                    'lesson_key' => $question['lesson_key'] ?? null,
                ],
                $currentSet,
                array_keys($currentSet)
            ),
            'point_distribution' => $this->computePointDistribution($currentSet),
            'lesson_coverage' => $this->computeLessonCoverage($currentSet),
        ];
    }
}
