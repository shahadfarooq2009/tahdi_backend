<?php

namespace App\Services\Play;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\UserPlayQuestion;
use App\Models\UserPlaySet;
use App\Services\Ai\AiService;
use App\Services\Curriculum\DocumentTextExtractionService;
use App\Support\Utf8Text;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserPlaySetService
{
    public function __construct(
        private readonly DocumentTextExtractionService $documents,
        private readonly PlaySetDocumentContextService $documentContext,
        private readonly AiService $ai,
    ) {}

    /**
     * @return array{play_set: array<string, mixed>, questions: array<int, array<string, mixed>>, used_fallback: bool}
     */
    public function generateFromUpload(
        string $userId,
        UploadedFile $file,
        ?string $title = null,
        ?int $pageFrom = null,
        ?int $pageTo = null,
    ): array {
        $content = $this->documents->extractFromUploadedFile($file, $pageFrom, $pageTo);
        $setTitle = Utf8Text::sanitize($title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $aiResult = $this->ai->generatePlaySetQuestionsFromDocument($setTitle, $content);

        return DB::transaction(function () use ($userId, $setTitle, $file, $content, $aiResult) {
            $playSet = UserPlaySet::query()->create([
                'user_id' => $userId,
                'title' => $setTitle,
                'source_file_name' => $file->getClientOriginalName(),
                'source_content' => Utf8Text::sanitize($this->documentContext->storeExcerpt($content)),
                'status' => 'draft',
                'question_count' => 0,
            ]);

            $questions = [];
            foreach ($aiResult['questions'] as $index => $row) {
                $question = UserPlayQuestion::query()->create([
                    'play_set_id' => $playSet->id,
                    'question_text' => Utf8Text::sanitize($row['question']),
                    'answer_text' => Utf8Text::sanitize($row['answer']),
                    'points_value' => (int) $row['points'],
                    'sort_order' => $index + 1,
                    'is_approved' => true,
                    'ai_generated' => true,
                ]);
                $questions[] = $this->serializeQuestion($question);
            }

            $playSet->update(['question_count' => count($questions)]);

            return [
                'play_set' => $this->serializeSet($playSet->fresh()),
                'questions' => $questions,
                'used_fallback' => $aiResult['usedFallback'],
            ];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(string $userId): array
    {
        return UserPlaySet::query()
            ->where('user_id', $userId)
            ->where('status', 'saved')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (UserPlaySet $set) => $this->serializeSet($set))
            ->all();
    }

    /**
     * @return array{play_set: array<string, mixed>, questions: array<int, array<string, mixed>>}
     */
    public function getForUser(string $userId, string $playSetId): array
    {
        $playSet = $this->findOwnedSet($userId, $playSetId, withQuestions: true);

        return [
            'play_set' => $this->serializeSet($playSet),
            'questions' => $playSet->questions->map(fn (UserPlayQuestion $q) => $this->serializeQuestion($q))->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array{play_set: array<string, mixed>, questions: array<int, array<string, mixed>>}
     */
    public function updateDraft(string $userId, string $playSetId, array $questions, ?string $title = null): array
    {
        $playSet = $this->findOwnedSet($userId, $playSetId);

        return DB::transaction(function () use ($playSet, $questions, $title) {
            $normalized = $this->normalizeQuestionRows($questions);
            $created = $this->replacePlaySetQuestions($playSet->id, $normalized);

            $updates = ['question_count' => count($created)];
            if (is_string($title) && trim($title) !== '') {
                $updates['title'] = Utf8Text::sanitize(trim($title));
            }

            $playSet->update($updates);
            $playSet->refresh();

            return [
                'play_set' => $this->serializeSet($playSet),
                'questions' => $created,
            ];
        });
    }

    /**
     * @return array{question: array<string, mixed>, used_fallback: bool}
     */
    public function regenerateQuestion(string $userId, string $playSetId, string $questionId): array
    {
        $playSet = $this->findOwnedSet($userId, $playSetId, withQuestions: true, withSource: true);

        $sourceContent = (string) ($playSet->source_content ?? '');

        if (trim($sourceContent) === '') {
            throw new ValidationException('لا يتوفر المحتوى المصدر لإعادة التوليد');
        }

        $targetQuestion = $playSet->questions->firstWhere('id', $questionId);

        if (! $targetQuestion) {
            throw new NotFoundException('السؤال غير موجود');
        }

        $existingQuestions = $playSet->questions
            ->filter(fn (UserPlayQuestion $question) => $question->id !== $questionId)
            ->pluck('question_text')
            ->map(fn (string $text) => trim($text))
            ->filter(fn (string $text) => $text !== '')
            ->values()
            ->all();

        $aiResult = $this->ai->regeneratePlaySetQuestionFromDocument(
            $playSet->title,
            $sourceContent,
            (int) $targetQuestion->points_value,
            $existingQuestions,
        );

        $targetQuestion->update([
            'question_text' => Utf8Text::sanitize($aiResult['question']),
            'answer_text' => Utf8Text::sanitize($aiResult['answer']),
            'points_value' => (int) $aiResult['points'],
            'is_approved' => true,
            'ai_generated' => true,
        ]);

        $playSet->touch();

        return [
            'question' => $this->serializeQuestion($targetQuestion->fresh()),
            'used_fallback' => $aiResult['usedFallback'],
        ];
    }

    /**
     * @return array{play_set: array<string, mixed>, questions: array<int, array<string, mixed>>}
     */
    public function save(string $userId, string $playSetId, array $questions, ?string $title = null): array
    {
        $playSet = $this->findOwnedSet($userId, $playSetId);

        if ($playSet->status === 'saved') {
            throw new ValidationException('المجموعة محفوظة مسبقاً');
        }

        $approved = array_map(
            fn (array $row) => [...$row, 'is_approved' => true],
            $this->normalizeQuestionRows($questions, approvedOnly: true),
        );

        if ($approved === []) {
            throw new ValidationException('يجب اعتماد سؤال واحد على الأقل قبل الحفظ');
        }

        return DB::transaction(function () use ($playSet, $approved, $title) {
            $savedQuestions = $this->replacePlaySetQuestions($playSet->id, $approved);

            $updates = [
                'status' => 'saved',
                'question_count' => count($savedQuestions),
            ];

            if (is_string($title) && trim($title) !== '') {
                $updates['title'] = Utf8Text::sanitize(trim($title));
            }

            $playSet->update($updates);
            $playSet->refresh();

            return [
                'play_set' => $this->serializeSet($playSet),
                'questions' => $savedQuestions,
            ];
        });
    }

    public function delete(string $userId, string $playSetId): void
    {
        $playSet = $this->findOwnedSet($userId, $playSetId);
        $playSet->delete();
    }

    private function findOwnedSet(
        string $userId,
        string $playSetId,
        bool $withQuestions = false,
        bool $withSource = false,
    ): UserPlaySet {
        $columns = [
            'id',
            'user_id',
            'title',
            'source_file_name',
            'status',
            'question_count',
            'created_at',
            'updated_at',
        ];

        if ($withSource) {
            $columns[] = 'source_content';
        }

        $query = UserPlaySet::query()
            ->select($columns)
            ->where('id', $playSetId)
            ->where('user_id', $userId);

        if ($withQuestions) {
            $query->with('questions');
        }

        $playSet = $query->first();

        if (! $playSet) {
            throw new NotFoundException('مجموعة الأسئلة غير موجودة');
        }

        return $playSet;
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array{question_text: string, answer_text: string, points_value: int, is_approved: bool, ai_generated: bool}>
     */
    private function normalizeQuestionRows(array $questions, bool $approvedOnly = false): array
    {
        $normalized = [];

        foreach (array_values($questions) as $row) {
            $isApproved = (bool) ($row['is_approved'] ?? true);

            if ($approvedOnly && ! $isApproved) {
                continue;
            }

            $questionText = Utf8Text::sanitize(trim((string) ($row['question_text'] ?? '')));
            $answerText = Utf8Text::sanitize(trim((string) ($row['answer_text'] ?? '')));

            if ($questionText === '' || $answerText === '') {
                continue;
            }

            $normalized[] = [
                'question_text' => $questionText,
                'answer_text' => $answerText,
                'points_value' => (int) ($row['points_value'] ?? 200),
                'is_approved' => $isApproved,
                'ai_generated' => (bool) ($row['ai_generated'] ?? false),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{question_text: string, answer_text: string, points_value: int, is_approved: bool, ai_generated: bool}>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function replacePlaySetQuestions(string $playSetId, array $rows): array
    {
        UserPlayQuestion::query()->where('play_set_id', $playSetId)->delete();

        if ($rows === []) {
            return [];
        }

        $now = now();
        $insertRows = [];
        $serialized = [];

        foreach ($rows as $index => $row) {
            $id = (string) Str::uuid();
            $sortOrder = $index + 1;

            $insertRows[] = [
                'id' => $id,
                'play_set_id' => $playSetId,
                'question_text' => $row['question_text'],
                'answer_text' => $row['answer_text'],
                'points_value' => $row['points_value'],
                'sort_order' => $sortOrder,
                'is_approved' => $row['is_approved'],
                'ai_generated' => $row['ai_generated'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $serialized[] = $this->serializeQuestionRow(
                $id,
                $row['question_text'],
                $row['answer_text'],
                $row['points_value'],
                $sortOrder,
                $row['is_approved'],
                $row['ai_generated'],
            );
        }

        UserPlayQuestion::query()->insert($insertRows);

        return $serialized;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeQuestionRow(
        string $id,
        string $questionText,
        string $answerText,
        int $pointsValue,
        int $sortOrder,
        bool $isApproved,
        bool $aiGenerated,
    ): array {
        return [
            'id' => $id,
            'question_text' => $questionText,
            'answer_text' => $answerText,
            'points_value' => $pointsValue,
            'sort_order' => $sortOrder,
            'is_approved' => $isApproved,
            'ai_generated' => $aiGenerated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSet(UserPlaySet $set): array
    {
        return [
            'id' => $set->id,
            'title' => $set->title,
            'source_file_name' => $set->source_file_name,
            'status' => $set->status,
            'question_count' => $set->question_count,
            'created_at' => $set->created_at?->toIso8601String(),
            'updated_at' => $set->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeQuestion(UserPlayQuestion $question): array
    {
        return [
            'id' => $question->id,
            'question_text' => $question->question_text,
            'answer_text' => $question->answer_text,
            'points_value' => $question->points_value,
            'sort_order' => $question->sort_order,
            'is_approved' => $question->is_approved,
            'ai_generated' => (bool) ($question->ai_generated ?? false),
        ];
    }
}
