<?php

namespace App\Services\Me;

use Illuminate\Support\Facades\DB;

class ViewedQuestionService
{
    /**
     * @return array<int, string>
     */
    public function listQuestionIds(string $userId): array
    {
        return DB::table('viewed_questions')
            ->where('user_id', $userId)
            ->pluck('question_id')
            ->all();
    }

    public function mark(string $userId, string $questionId): void
    {
        DB::table('viewed_questions')->upsert(
            [
                'user_id' => $userId,
                'question_id' => $questionId,
                'viewed_at' => now(),
            ],
            ['user_id', 'question_id'],
            ['viewed_at']
        );
    }

    public function reset(string $userId): void
    {
        DB::table('viewed_questions')->where('user_id', $userId)->delete();
    }
}
