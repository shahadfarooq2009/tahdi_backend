<?php

namespace App\Services\Curriculum;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Support\CurriculumConfig;
use Illuminate\Support\Facades\DB;

class ReviewSetUsageService
{
    /**
     * @return array<string, mixed>
     */
    public function selectNextReviewSet(string $textbookId, string $unitKey, string $hostUserId, string $className): array
    {
        $normalized = $this->normalizeScope($textbookId, $unitKey, $hostUserId, $className);
        $allSets = $this->listReviewSetsForUnit($normalized['textbookId'], $normalized['unitKey']);
        $usedIds = $this->getUsedReviewSetIds($normalized);

        $playableSets = collect($allSets)
            ->filter(fn ($set) => (bool) $set->is_playable)
            ->sortBy('sequence_number')
            ->values();

        $usedSet = array_flip($usedIds);
        $nextUnused = $playableSets->first(fn ($set) => ! isset($usedSet[$set->id]));

        if ($nextUnused) {
            $remaining = $playableSets->filter(fn ($set) => ! isset($usedSet[$set->id]));

            return [
                'status' => 'available',
                'review_set' => (array) $nextUnused,
                'remaining_sets' => $remaining->count(),
                'sequence_number' => $nextUnused->sequence_number,
            ];
        }

        if ($playableSets->isEmpty()) {
            return [
                'status' => 'no_sets',
                'message' => 'لا توجد مجموعات مراجعة جاهزة لهذه الوحدة',
                'options' => ['generate_unit_questions'],
            ];
        }

        return [
            'status' => 'exhausted',
            'message' => 'تم استخدام جميع مجموعات المراجعة',
            'total_playable_sets' => $playableSets->count(),
            'options' => ['restart_from_set_1', 'generate_additional_set', 'contact_admin'],
            'playable_sets' => $playableSets->map(fn ($set) => [
                'id' => $set->id,
                'sequence_number' => $set->sequence_number,
                'total_questions' => $set->total_questions,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRemainingReviewSetCount(string $textbookId, string $unitKey, string $hostUserId, string $className): array
    {
        $normalized = $this->normalizeScope($textbookId, $unitKey, $hostUserId, $className);
        $allSets = $this->listReviewSetsForUnit($normalized['textbookId'], $normalized['unitKey']);
        $usedIds = $this->getUsedReviewSetIds($normalized);

        $playableSets = collect($allSets)->filter(fn ($set) => (bool) $set->is_playable);
        $usedSet = array_flip($usedIds);
        $remaining = $playableSets->filter(fn ($set) => ! isset($usedSet[$set->id]));

        return [
            'textbook_id' => $normalized['textbookId'],
            'unit_key' => $normalized['unitKey'],
            'host_user_id' => $normalized['hostUserId'],
            'class_name' => $normalized['className'],
            'total_playable_sets' => $playableSets->count(),
            'used_sets' => count($usedIds),
            'remaining_sets' => $remaining->count(),
            'remaining_sequence_numbers' => $remaining->pluck('sequence_number')->values()->all(),
            'exhausted' => $playableSets->isNotEmpty() && $remaining->isEmpty(),
        ];
    }

    /**
     * @return array{usage: object, remaining_sets: int}
     */
    public function recordReviewSetUsage(
        string $reviewSetId,
        string $textbookId,
        string $unitKey,
        string $hostUserId,
        string $className,
        ?string $gameSessionId = null,
    ): array {
        $className = trim($className);

        try {
            $id = (string) \Illuminate\Support\Str::uuid();
            DB::table('curriculum_review_set_usage')->insert([
                'id' => $id,
                'review_set_id' => $reviewSetId,
                'textbook_id' => $textbookId,
                'unit_key' => $unitKey,
                'host_user_id' => $hostUserId,
                'class_name' => $className,
                'game_session_id' => $gameSessionId,
                'created_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                throw new ValidationException('Review set already used in this teacher/class context');
            }
            throw $exception;
        }

        $row = DB::table('curriculum_review_set_usage')->where('id', $id)->first();
        $remaining = $this->getRemainingReviewSetCount($textbookId, $unitKey, $hostUserId, $className);

        return [
            'usage' => $row,
            'remaining_sets' => $remaining['remaining_sets'],
        ];
    }

    /**
     * @return array<int, object>
     */
    public function getReviewSetQuestions(string $reviewSetId): array
    {
        $reviewSet = DB::table('curriculum_review_sets')->where('id', $reviewSetId)->first();

        if (! $reviewSet) {
            throw new NotFoundException('Review set not found');
        }

        return DB::table('curriculum_review_set_questions')
            ->where('review_set_id', $reviewSetId)
            ->orderBy('position')
            ->get()
            ->all();
    }

    /**
     * @return array<int, object>
     */
    public function listReviewSetsForUnit(string $textbookId, string $unitKey): array
    {
        return DB::table('curriculum_review_sets')
            ->where('textbook_id', $textbookId)
            ->where('unit_key', $unitKey)
            ->orderBy('sequence_number')
            ->get()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUnitGenerationStatuses(string $textbookId): array
    {
        return DB::table('curriculum_unit_generation_status')
            ->where('textbook_id', $textbookId)
            ->orderBy('unit_title')
            ->get()
            ->map(function ($row) {
                $data = (array) $row;
                $data['config'] = CurriculumConfig::publicConfig();

                return $data;
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getReviewSetDetails(string $reviewSetId): array
    {
        $reviewSet = DB::table('curriculum_review_sets')->where('id', $reviewSetId)->first();

        if (! $reviewSet) {
            throw new NotFoundException('Review set not found');
        }

        $questions = DB::table('curriculum_review_set_questions as rsq')
            ->leftJoin('ai_generated_questions as agq', 'agq.id', '=', 'rsq.generated_question_id')
            ->where('rsq.review_set_id', $reviewSetId)
            ->orderBy('rsq.position')
            ->select([
                'rsq.id',
                'rsq.position',
                'rsq.points_value',
                'rsq.lesson_key',
                'rsq.question_id',
                'rsq.generated_question_id',
                'agq.id as ai_id',
                'agq.question_text',
                'agq.answer_text',
                'agq.validation_status',
                'agq.confidence_score',
                'agq.source_page_start',
                'agq.source_page_end',
                'agq.lesson_key as ai_lesson_key',
                'agq.unit_key as ai_unit_key',
            ])
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'position' => $row->position,
                    'points_value' => $row->points_value,
                    'lesson_key' => $row->lesson_key,
                    'question_id' => $row->question_id,
                    'generated_question_id' => $row->generated_question_id,
                    'ai_generated_questions' => $row->ai_id ? [
                        'id' => $row->ai_id,
                        'question_text' => $row->question_text,
                        'answer_text' => $row->answer_text,
                        'validation_status' => $row->validation_status,
                        'confidence_score' => $row->confidence_score,
                        'source_page_start' => $row->source_page_start,
                        'source_page_end' => $row->source_page_end,
                        'lesson_key' => $row->ai_lesson_key,
                        'unit_key' => $row->ai_unit_key,
                    ] : null,
                ];
            })
            ->all();

        return [
            'review_set' => $reviewSet,
            'questions' => $questions,
            'point_distribution' => json_decode($reviewSet->point_distribution ?? '{}', true),
            'lesson_coverage' => json_decode($reviewSet->lesson_coverage ?? '{}', true),
        ];
    }

    /**
     * @param  array{textbookId: string, unitKey: string, hostUserId: string, className: string}  $scope
     * @return string[]
     */
    private function getUsedReviewSetIds(array $scope): array
    {
        return DB::table('curriculum_review_set_usage')
            ->where('textbook_id', $scope['textbookId'])
            ->where('unit_key', $scope['unitKey'])
            ->where('host_user_id', $scope['hostUserId'])
            ->where('class_name', $scope['className'])
            ->pluck('review_set_id')
            ->all();
    }

    /**
     * @return array{textbookId: string, unitKey: string, hostUserId: string, className: string}
     */
    private function normalizeScope(string $textbookId, string $unitKey, string $hostUserId, string $className): array
    {
        return [
            'textbookId' => $textbookId,
            'unitKey' => $unitKey,
            'hostUserId' => $hostUserId,
            'className' => trim($className),
        ];
    }
}
