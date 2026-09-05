<?php

namespace App\Services\Curriculum;

use App\Exceptions\ValidationException;
use App\Models\Chapter;
use App\Services\Admin\ChapterService;

class ChapterMappingService
{
    public function __construct(
        private readonly ChapterService $chapters,
    ) {}

    /**
     * @return array{chapter_id: string, match_type: string}
     */
    public function resolveChapterForAiQuestion(
        string $subjectId,
        string $unitTitle,
        ?string $chapterId,
        bool $createChapter,
        string $actorUserId,
    ): array {
        if ($chapterId) {
            $exists = Chapter::query()
                ->where('subject_id', $subjectId)
                ->where('id', $chapterId)
                ->exists();

            if (! $exists) {
                throw new ValidationException('Selected chapter_id does not belong to the textbook subject');
            }

            return ['chapter_id' => $chapterId, 'match_type' => 'manual'];
        }

        $match = $this->findChapterForUnitTitle($subjectId, $unitTitle);

        if ($match['chapter_id']) {
            return ['chapter_id' => $match['chapter_id'], 'match_type' => (string) $match['match_type']];
        }

        if ($createChapter) {
            $created = $this->chapters->resolveChapter($actorUserId, $subjectId, null, $unitTitle);

            return ['chapter_id' => $created['chapter_id'], 'match_type' => 'created'];
        }

        $exception = new ValidationException('Chapter mapping required before approving this AI question', [
            'code' => 'CHAPTER_MAPPING_REQUIRED',
            'unit_title' => $unitTitle,
            'candidates' => $match['candidates'],
            'ambiguous_matches' => $match['ambiguous_matches'] ?? [],
        ]);

        throw $exception;
    }
    /**
     * @return array{chapter_id: ?string, match_type: ?string, candidates: array<int, array<string, mixed>>, ambiguous_matches?: array<int, array<string, mixed>>}
     */
    public function findChapterForUnitTitle(?string $subjectId, string $unitTitle): array
    {
        if (! $subjectId) {
            return [
                'chapter_id' => null,
                'match_type' => null,
                'candidates' => [],
            ];
        }

        $chapters = Chapter::query()
            ->where('subject_id', $subjectId)
            ->orderBy('chapter_number')
            ->get(['id', 'name', 'chapter_number'])
            ->map(fn (Chapter $chapter) => [
                'id' => $chapter->id,
                'name' => $chapter->name,
                'chapter_number' => $chapter->chapter_number,
            ])
            ->all();

        $normalizedTarget = ArabicTextService::normalizeForComparison($unitTitle);

        if ($normalizedTarget === '') {
            return ['chapter_id' => null, 'match_type' => null, 'candidates' => $chapters];
        }

        foreach ($chapters as $chapter) {
            if (ArabicTextService::normalizeForComparison($chapter['name']) === $normalizedTarget) {
                return [
                    'chapter_id' => $chapter['id'],
                    'match_type' => 'exact',
                    'candidates' => $chapters,
                ];
            }
        }

        $partialMatches = array_values(array_filter(
            $chapters,
            function (array $chapter) use ($normalizedTarget): bool {
                $normalizedName = ArabicTextService::normalizeForComparison($chapter['name']);

                if ($normalizedName === '') {
                    return false;
                }

                return str_contains($normalizedName, $normalizedTarget)
                    || str_contains($normalizedTarget, $normalizedName);
            }
        ));

        if (count($partialMatches) === 1) {
            return [
                'chapter_id' => $partialMatches[0]['id'],
                'match_type' => 'normalized',
                'candidates' => $chapters,
            ];
        }

        return [
            'chapter_id' => null,
            'match_type' => null,
            'candidates' => $chapters,
            'ambiguous_matches' => $partialMatches,
        ];
    }
}
