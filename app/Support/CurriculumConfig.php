<?php

namespace App\Support;

final class CurriculumConfig
{
    /**
     * @return array<int, int>
     */
    public static function reviewSetPointTargets(): array
    {
        $targets = [];

        foreach (config('curriculum.point_values', QuestionConstants::POINT_VALUES) as $points) {
            $targets[$points] = config('curriculum.questions_per_point_tier', 4);
        }

        return $targets;
    }

    /**
     * @return array<int, int>
     */
    public static function unitPointTierTargets(): array
    {
        $perTier = (int) ceil(
            config('curriculum.target_questions_per_unit', 60)
            / count(config('curriculum.point_values', QuestionConstants::POINT_VALUES))
        );

        $targets = [];

        foreach (config('curriculum.point_values', QuestionConstants::POINT_VALUES) as $points) {
            $targets[$points] = $perTier;
        }

        return $targets;
    }

    /**
     * @return array<string, int>
     */
    public static function publicConfig(): array
    {
        return [
            'target_questions_per_unit' => config('curriculum.target_questions_per_unit', 60),
            'questions_per_review_set' => config('curriculum.questions_per_review_set', 20),
            'min_playable_review_set_size' => config('curriculum.min_playable_review_set_size', 15),
        ];
    }
}
