<?php

namespace App\Services\Play;

use App\Exceptions\ValidationException;
use App\Models\UserPlayQuestion;
use App\Models\UserPlaySet;
use App\Services\Game\GameSessionService;
use App\Support\Game\BoardConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserPlaySetGameService
{
    public function __construct(
        private readonly GameSessionService $sessions,
    ) {}

    /**
     * @param  array{class_name: string, teams: array<int, array{name: string, avatar_url?: string|null, color?: string|null}>, selected_powers: array<int, string>}  $payload
     * @return array<string, mixed>
     */
    public function startGame(string $userId, string $playSetId, array $payload): array
    {
        $playSet = UserPlaySet::query()
            ->where('id', $playSetId)
            ->where('user_id', $userId)
            ->with('questions')
            ->first();

        if (! $playSet) {
            throw new ValidationException('مجموعة الأسئلة غير موجودة');
        }

        if ($playSet->status !== 'saved') {
            throw new ValidationException('يجب حفظ اللعبة قبل تشغيلها');
        }

        $boardConfig = BoardConfig::forMode('school');
        $requiredQuestions = $boardConfig['rows'] * $boardConfig['cols'];

        $approvedQuestions = $playSet->questions
            ->filter(fn (UserPlayQuestion $question) => $question->is_approved)
            ->sortBy('sort_order')
            ->values();

        if ($approvedQuestions->count() < $requiredQuestions) {
            throw new ValidationException("يجب أن تحتوي اللعبة على {$requiredQuestions} سؤالاً معتمداً على الأقل");
        }

        $selectedQuestions = $approvedQuestions->take($requiredQuestions);

        return DB::transaction(function () use ($playSet, $payload, $selectedQuestions, $boardConfig, $userId) {
            $supportsDirectQuestions = Schema::hasColumn(
                'game_session_questions',
                'user_play_question_id'
            );

            $assignments = $supportsDirectQuestions
                ? $this->buildBoardAssignments($selectedQuestions, $boardConfig)
                : $this->buildPromotedBoardAssignments(
                    $selectedQuestions,
                    $boardConfig,
                    $userId,
                );

            $metadata = [
                'play_set_id' => $playSet->id,
                'play_set_title' => $playSet->title,
                'question_source' => 'user_play_set',
                'selected_powers' => $payload['selected_powers'],
            ];

            $session = $this->sessions->createPlaySetGameSession(
                $userId,
                $payload['class_name'],
                $payload['teams'],
                $metadata,
                $assignments,
            );

            return array_merge($session, [
                'questions' => $this->formatQuestionsForClient($selectedQuestions, $assignments, $playSet->id),
            ]);
        });
    }

    /**
     * @param  Collection<int, UserPlayQuestion>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function buildBoardAssignments(Collection $questions, array $boardConfig): array
    {
        $assignments = [];
        $cols = (int) $boardConfig['cols'];

        foreach ($questions as $index => $question) {
            $assignments[] = [
                'user_play_question_id' => $question->id,
                'question_id' => null,
                'subject_id' => null,
                'row_position' => intdiv($index, $cols),
                'col_position' => $index % $cols,
                'points_value' => (int) $question->points_value,
            ];
        }

        return $assignments;
    }

    /**
     * @param  Collection<int, UserPlayQuestion>  $questions
     * @param  array<int, array<string, mixed>>  $assignments
     * @return array<int, array<string, mixed>>
     */
    private function formatQuestionsForClient(Collection $questions, array $assignments, string $playSetId): array
    {
        $formatted = [];

        foreach ($questions->values() as $index => $question) {
            $assignment = $assignments[$index];

            $formatted[] = [
                'id' => $question->id,
                'question_text' => $question->question_text,
                'points_value' => $assignment['points_value'],
                'image_url' => null,
                'question_type' => null,
                'subject_id' => $playSetId,
                'row' => $assignment['row_position'],
                'col' => $assignment['col_position'],
                'chapter_id' => null,
                'category_id' => null,
                'grade' => null,
                'unit' => null,
            ];
        }

        return $formatted;
    }

    /**
     * Compatibility path used until the direct-question migration is applied.
     *
     * @param  Collection<int, UserPlayQuestion>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function buildPromotedBoardAssignments(
        Collection $questions,
        array $boardConfig,
        string $hostUserId,
    ): array {
        $categoryId = $this->resolveQuestionBankCategoryId();
        $questionRows = [];
        $assignments = [];
        $cols = (int) $boardConfig['cols'];
        $now = now();

        foreach ($questions->values() as $index => $question) {
            // Reuse the play-question UUID so the client can open it immediately
            // without waiting for the session assignment response.
            $questionId = (string) $question->id;
            $pointsValue = (int) $question->points_value;

            $questionRows[] = [
                'id' => $questionId,
                'category_id' => $categoryId,
                'question_text' => $question->question_text,
                'answer_text' => $question->answer_text,
                'points_value' => $pointsValue,
                'approval_status' => 'approved',
                'approved_by' => $hostUserId,
                'approved_at' => $now,
                'submitted_by' => $hostUserId,
                'submitted_at' => $now,
                'question_source' => 'manual',
                'ai_generated' => (bool) ($question->ai_generated ?? true),
                'is_deleted' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $assignments[] = [
                'question_id' => $questionId,
                'subject_id' => null,
                'row_position' => intdiv($index, $cols),
                'col_position' => $index % $cols,
                'points_value' => $pointsValue,
            ];
        }

        DB::table('questions')->insertOrIgnore($questionRows);

        return $assignments;
    }

    private function resolveQuestionBankCategoryId(): string
    {
        $configured = config('play_sets.question_bank_category_id');
        if (is_string($configured) && $configured !== '') {
            $exists = DB::table('categories')
                ->where('id', $configured)
                ->where('is_deleted', false)
                ->exists();

            if ($exists) {
                return $configured;
            }
        }

        $categoryId = DB::table('categories')
            ->where('is_deleted', false)
            ->orderBy('created_at')
            ->value('id');

        if (! is_string($categoryId) || $categoryId === '') {
            throw new ValidationException('تعذر تجهيز اللعبة: لا توجد فئة أسئلة متاحة في النظام');
        }

        return $categoryId;
    }
}
