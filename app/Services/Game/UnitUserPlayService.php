<?php

namespace App\Services\Game;

use App\Exceptions\ValidationException;
use App\Support\QuestionConstants;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UnitUserPlayService
{
    /**
     * @return array<string, mixed>
     */
    public function getPlayAvailability(string $userId, string $chapterId): array
    {
        if (! $this->isEnabled()) {
            return $this->emptyAvailability('disabled');
        }

        $approvedCounts = $this->countApprovedQuestionsByPoints($chapterId);
        $unusedCounts = $this->countUnusedQuestionsByPoints($userId, $chapterId);
        $perTier = $this->questionsPerPointTier();
        $remainingPlays = $this->calculateRemainingPlays($unusedCounts, $perTier);
        $totalApproved = array_sum($approvedCounts);

        if ($totalApproved === 0) {
            return $this->emptyAvailability('not_ready', $approvedCounts, $unusedCounts);
        }

        $assignedCount = $this->countAssignedQuestions($userId, $chapterId);
        $status = 'available';

        if ($remainingPlays <= 0) {
            $status = $assignedCount > 0 ? 'completed' : 'not_ready';
        }

        return [
            'enabled' => true,
            'status' => $status,
            'remaining_plays' => $remainingPlays,
            'remaining_label' => $this->buildRemainingLabel($remainingPlays, $assignedCount),
            'approved_by_points' => $approvedCounts,
            'unused_by_points' => $unusedCounts,
            'questions_per_point_tier' => $perTier,
            'questions_per_session' => $perTier * count(QuestionConstants::POINT_VALUES),
            'total_approved_questions' => $totalApproved,
            'assigned_questions' => $assignedCount,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selectSessionQuestions(
        string $userId,
        string $chapterId,
        string $gameSessionId,
    ): array {
        if (! $this->isEnabled()) {
            throw new ValidationException('User-specific unit play tracking is not available.');
        }

        $availability = $this->getPlayAvailability($userId, $chapterId);

        if (($availability['remaining_plays'] ?? 0) <= 0) {
            throw (new ValidationException('لا توجد جلسات لعب متبقية لهذه الوحدة.'))
                ->withDetails(['code' => 'UNIT_PLAY_EXHAUSTED']);
        }

        $perTier = $this->questionsPerPointTier();
        $usedIds = array_flip($this->getAssignedQuestionIds($userId, $chapterId));
        $selected = [];

        foreach (QuestionConstants::POINT_VALUES as $points) {
            $candidates = $this->getApprovedQuestionsForChapter($chapterId)
                ->filter(fn ($question) => (int) $question->points_value === $points && ! isset($usedIds[$question->id]))
                ->shuffle()
                ->take($perTier)
                ->values();

            if ($candidates->count() < $perTier) {
                throw (new ValidationException('لا يوجد عدد كافٍ من الأسئلة المتوازنة لهذه الوحدة.'))
                    ->withDetails(['code' => 'UNIT_PLAY_UNBALANCED', 'points' => $points]);
            }

            foreach ($candidates as $question) {
                $selected[] = $question;
            }
        }

        $selected = collect($selected)->shuffle()->values();
        $now = now();
        $rows = [];

        foreach ($selected as $question) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'chapter_id' => $chapterId,
                'question_id' => $question->id,
                'game_session_id' => $gameSessionId,
                'points_value' => (int) $question->points_value,
                'assigned_at' => $now,
                'completed_at' => null,
            ];
        }

        DB::table('unit_user_question_assignments')->insert($rows);

        return $selected->map(fn ($question) => (array) $question)->all();
    }

    /**
     * @param  array<int|string, int>  $unusedCounts
     */
    private function calculateRemainingPlays(array $unusedCounts, int $perTier): int
    {
        if ($perTier <= 0) {
            return 0;
        }

        $remaining = PHP_INT_MAX;

        foreach (QuestionConstants::POINT_VALUES as $points) {
            $unused = (int) ($unusedCounts[(string) $points] ?? 0);
            $remaining = min($remaining, intdiv($unused, $perTier));
        }

        return $remaining === PHP_INT_MAX ? 0 : max(0, $remaining);
    }

    /**
     * @return array<string, int>
     */
    private function countApprovedQuestionsByPoints(string $chapterId): array
    {
        $counts = $this->emptyPointCounts();

        foreach ($this->getApprovedQuestionsForChapter($chapterId) as $question) {
            $key = (string) $question->points_value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function countUnusedQuestionsByPoints(string $userId, string $chapterId): array
    {
        $counts = $this->countApprovedQuestionsByPoints($chapterId);
        $usedIds = $this->getAssignedQuestionIds($userId, $chapterId);

        if ($usedIds === []) {
            return $counts;
        }

        $usedByPoints = $this->emptyPointCounts();

        foreach ($this->getApprovedQuestionsForChapter($chapterId) as $question) {
            if (in_array($question->id, $usedIds, true)) {
                $key = (string) $question->points_value;
                $usedByPoints[$key] = ($usedByPoints[$key] ?? 0) + 1;
            }
        }

        $unused = $this->emptyPointCounts();

        foreach (QuestionConstants::POINT_VALUES as $points) {
            $key = (string) $points;
            $unused[$key] = max(0, ($counts[$key] ?? 0) - ($usedByPoints[$key] ?? 0));
        }

        return $unused;
    }

    /**
     * @return Collection<int, object>
     */
    private function getApprovedQuestionsForChapter(string $chapterId): Collection
    {
        $query = DB::table('questions')
            ->select(['id', 'question_text', 'answer_text', 'points_value', 'chapter_id'])
            ->where('chapter_id', $chapterId)
            ->where('approval_status', 'approved')
            ->whereIn('points_value', QuestionConstants::POINT_VALUES);

        if (Schema::hasColumn('questions', 'is_deleted')) {
            $query->where(function ($builder) {
                $builder->where('is_deleted', false)->orWhereNull('is_deleted');
            });
        }

        return $query->get();
    }

    /**
     * @return string[]
     */
    private function getAssignedQuestionIds(string $userId, string $chapterId): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        return DB::table('unit_user_question_assignments')
            ->where('user_id', $userId)
            ->where('chapter_id', $chapterId)
            ->pluck('question_id')
            ->all();
    }

    private function countAssignedQuestions(string $userId, string $chapterId): int
    {
        return count($this->getAssignedQuestionIds($userId, $chapterId));
    }

    private function questionsPerPointTier(): int
    {
        return max(1, (int) config('curriculum.questions_per_point_tier', 4));
    }

    private function isEnabled(): bool
    {
        return Schema::hasTable('unit_user_question_assignments');
    }

    /**
     * @return array<string, int>
     */
    private function emptyPointCounts(): array
    {
        $counts = [];

        foreach (QuestionConstants::POINT_VALUES as $points) {
            $counts[(string) $points] = 0;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>|null  $approvedCounts
     * @param  array<string, int>|null  $unusedCounts
     * @return array<string, mixed>
     */
    private function emptyAvailability(
        string $status,
        ?array $approvedCounts = null,
        ?array $unusedCounts = null,
    ): array {
        $perTier = $this->questionsPerPointTier();

        return [
            'enabled' => $status !== 'disabled',
            'status' => $status,
            'remaining_plays' => 0,
            'remaining_label' => $status === 'not_ready' ? 'غير جاهز' : 'غير متاح',
            'approved_by_points' => $approvedCounts ?? $this->emptyPointCounts(),
            'unused_by_points' => $unusedCounts ?? $this->emptyPointCounts(),
            'questions_per_point_tier' => $perTier,
            'questions_per_session' => $perTier * count(QuestionConstants::POINT_VALUES),
            'total_approved_questions' => array_sum($approvedCounts ?? []),
            'assigned_questions' => 0,
        ];
    }

    private function buildRemainingLabel(int $remainingPlays, int $assignedCount): string
    {
        if ($remainingPlays <= 0) {
            return $assignedCount > 0 ? 'مكتمل' : 'غير جاهز';
        }

        if ($assignedCount === 0) {
            return $remainingPlays === 1 ? 'مرة لعب واحدة' : "{$remainingPlays} مرات لعب متاحة";
        }

        return $remainingPlays === 1 ? 'متبقي 1' : "متبقي {$remainingPlays}";
    }
}
