<?php

namespace App\Services\Game;

use App\Exceptions\ValidationException;
use App\Services\Curriculum\ReviewSetUsageService;
use App\Services\Curriculum\UnitMappingService;
use App\Support\Game\BoardConfig;

class SchoolReviewSetSelectionService
{
    public function __construct(
        private readonly UnitMappingService $unitMapping,
        private readonly ReviewSetUsageService $reviewSetUsage,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public function resolveSchoolReviewSetSession(
        string $subjectId,
        string $hostUserId,
        ?array $metadata,
        ?string $className,
    ): array {
        $context = $this->unitMapping->resolveSchoolUnitContext($subjectId, $metadata, $hostUserId);

        if (! $context['textbook']) {
            throw (new ValidationException('لم يتم تجهيز أسئلة هذه الوحدة من الكتاب المدرسي بعد.'))
                ->withDetails(['code' => 'SCHOOL_TEXTBOOK_NOT_FOUND', 'chapter_name' => $context['chapter_name']]);
        }

        if (! $context['unit_key']) {
            throw (new ValidationException('لم يتم ربط هذه الوحدة بالكتاب المدرسي بعد. يرجى مراجعة الإدارة.'))
                ->withDetails([
                    'code' => 'UNIT_MAPPING_REQUIRED',
                    'textbook_id' => $context['textbook']['id'],
                    'chapter_id' => $context['chapter_id'],
                    'chapter_name' => $context['chapter_name'],
                    'unit_candidates' => $context['unit_candidates'],
                ]);
        }

        $selection = $this->reviewSetUsage->selectNextReviewSet(
            $context['textbook']['id'],
            $context['unit_key'],
            $hostUserId,
            $className ?? ''
        );

        if ($selection['status'] !== 'available') {
            $message = $selection['status'] === 'exhausted'
                ? 'تم استخدام جميع مجموعات المراجعة لهذه الوحدة.'
                : 'لا توجد أسئلة جاهزة لهذه الوحدة حالياً.';

            throw (new ValidationException($message))->withDetails([
                'code' => 'SCHOOL_REVIEW_SET_UNAVAILABLE',
                'status' => $selection['status'],
                'textbook_id' => $context['textbook']['id'],
                'unit_key' => $context['unit_key'],
            ]);
        }

        $reviewSet = $selection['review_set'];
        $setQuestions = $this->reviewSetUsage->getReviewSetQuestions($reviewSet['id']);
        $boardConfig = BoardConfig::forMode('school');
        $built = $this->buildAssignmentsFromReviewSetQuestions($setQuestions, $boardConfig);

        if (! $built['complete']) {
            throw (new ValidationException('لا توجد أسئلة جاهزة لهذه الوحدة حالياً.'))->withDetails([
                'code' => 'SCHOOL_REVIEW_SET_INCOMPLETE',
                'review_set_id' => $reviewSet['id'],
                'available_questions' => $built['availableQuestions'],
                'required_questions' => $built['totalCells'],
            ]);
        }

        $enrichedMetadata = array_merge($metadata ?? [], [
            'textbook_id' => $context['textbook']['id'],
            'unit_key' => $context['unit_key'],
            'unit_title' => $context['unit_title'] ?? $context['chapter_name'],
            'review_set_id' => $reviewSet['id'],
            'review_set_sequence' => $reviewSet['sequence_number'],
            'question_source' => 'textbook_ai',
        ]);

        return [
            'context' => $context,
            'selection' => $selection,
            'reviewSet' => $reviewSet,
            'assignments' => $built['assignments'],
            'metadata' => $enrichedMetadata,
        ];
    }

    /**
     * @param  array<int, object|array<string, mixed>>  $setQuestions
     * @return array{assignments: array<int, array<string, mixed>>, totalCells: int, availableQuestions: int, complete: bool}
     */
    public function buildAssignmentsFromReviewSetQuestions(array $setQuestions, array $boardConfig): array
    {
        $totalCells = $boardConfig['rows'] * $boardConfig['cols'];
        $playable = collect($setQuestions)
            ->map(fn ($row) => (array) $row)
            ->filter(fn ($row) => ! empty($row['question_id']))
            ->sortBy('position')
            ->values();

        if ($playable->count() < $totalCells) {
            return [
                'assignments' => [],
                'totalCells' => $totalCells,
                'availableQuestions' => $playable->count(),
                'complete' => false,
            ];
        }

        $assignments = [];
        for ($i = 0; $i < $totalCells; $i++) {
            $row = $playable[$i];
            $assignments[] = [
                'question_id' => $row['question_id'],
                'row_position' => intdiv($i, $boardConfig['cols']),
                'col_position' => $i % $boardConfig['cols'],
                'points_value' => $row['points_value'] ?? 100,
            ];
        }

        return [
            'assignments' => $assignments,
            'totalCells' => $totalCells,
            'availableQuestions' => $playable->count(),
            'complete' => true,
        ];
    }
}
