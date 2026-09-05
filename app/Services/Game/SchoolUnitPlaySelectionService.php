<?php

namespace App\Services\Game;

use App\Exceptions\ValidationException;
use App\Support\Game\BoardConfig;

class SchoolUnitPlaySelectionService
{
    public function __construct(
        private readonly UnitUserPlayService $unitUserPlay,
        private readonly SchoolReviewSetSelectionService $reviewSetSelection,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    public function tryResolveDynamicSession(
        string $hostUserId,
        ?array $metadata,
        string $gameSessionId,
        array $boardConfig,
    ): ?array {
        $chapterId = is_string($metadata['chapter_id'] ?? null) ? trim($metadata['chapter_id']) : '';

        if ($chapterId === '') {
            return null;
        }

        $availability = $this->unitUserPlay->getPlayAvailability($hostUserId, $chapterId);

        if (($availability['total_approved_questions'] ?? 0) < ($availability['questions_per_session'] ?? 20)) {
            return null;
        }

        if (($availability['remaining_plays'] ?? 0) <= 0) {
            throw (new ValidationException('لا توجد جلسات لعب متبقية لهذه الوحدة.'))
                ->withDetails([
                    'code' => 'UNIT_PLAY_EXHAUSTED',
                    'remaining_label' => $availability['remaining_label'] ?? 'مكتمل',
                ]);
        }

        $selectedQuestions = $this->unitUserPlay->selectSessionQuestions(
            $hostUserId,
            $chapterId,
            $gameSessionId,
        );

        $built = $this->reviewSetSelection->buildAssignmentsFromReviewSetQuestions(
            array_map(fn (array $question) => (object) [
                'question_id' => $question['id'],
                'points_value' => $question['points_value'],
                'position' => 0,
            ], $selectedQuestions),
            $boardConfig,
        );

        if (! $built['complete']) {
            throw (new ValidationException('لا توجد أسئلة كافية لبدء جلسة متوازنة.'))
                ->withDetails(['code' => 'UNIT_PLAY_INCOMPLETE']);
        }

        $updatedAvailability = $this->unitUserPlay->getPlayAvailability($hostUserId, $chapterId);

        return [
            'assignments' => $built['assignments'],
            'metadata' => array_merge($metadata ?? [], [
                'question_source' => 'textbook_ai',
                'chapter_id' => $chapterId,
                'play_mode' => 'unit_question_bank',
                'remaining_plays' => $updatedAvailability['remaining_plays'] ?? 0,
                'remaining_label' => $updatedAvailability['remaining_label'] ?? null,
            ]),
            'availability' => $updatedAvailability,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getChapterPlayAvailability(string $userId, string $chapterId): array
    {
        return $this->unitUserPlay->getPlayAvailability($userId, $chapterId);
    }
}
