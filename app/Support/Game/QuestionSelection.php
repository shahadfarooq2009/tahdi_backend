<?php

namespace App\Support\Game;

final class QuestionSelection
{
    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    public static function selectForBoard(array $questions): array
    {
        $byPoints = fn (int $points, int $limit) => array_values(array_filter(
            $questions,
            fn ($q) => (int) ($q['points_value'] ?? 0) === $points
        ));

        $q100 = array_slice($byPoints(100, 2), 0, 2);
        $q200 = array_slice($byPoints(200, 1), 0, 1);
        $q300 = array_slice($byPoints(300, 1), 0, 1);
        $q400 = array_slice($byPoints(400, 1), 0, 1);
        $q500 = array_slice($byPoints(500, 2), 0, 2);

        return array_merge($q500, $q400, $q300, $q200, $q100);
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>|null  $placement
     * @return array<string, mixed>
     */
    public static function toSafe(array $question, ?array $placement = null): array
    {
        return [
            'id' => $question['id'],
            'question_text' => $question['question_text'],
            'points_value' => $question['points_value'],
            'image_url' => $question['image_url'] ?? null,
            'question_type' => $question['question_type'] ?? null,
            'subject_id' => $question['subject_id'] ?? $placement['subject_id'] ?? null,
            'row' => $placement['row_position'] ?? null,
            'col' => $placement['col_position'] ?? null,
            'chapter_id' => $question['chapter_id'] ?? null,
            'category_id' => $question['category_id'] ?? null,
            'grade' => $question['grade'] ?? null,
            'unit' => $question['unit'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    public static function toReveal(array $question): array
    {
        return [
            'id' => $question['id'],
            'answer_text' => $question['answer_text'] ?? '',
            'answer_image_url' => $question['answer_image_url'] ?? null,
            'explanation' => $question['explanation'] ?? null,
        ];
    }
}
