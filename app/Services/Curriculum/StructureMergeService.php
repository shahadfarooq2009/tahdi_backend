<?php

namespace App\Services\Curriculum;

class StructureMergeService
{
    public function __construct(
        private readonly ArabicStructureDetector $detector,
        private readonly StructureCoverageValidator $coverage,
    ) {}

    /**
     * Merge AI structure with deterministic baseline. Deterministic candidates are never dropped.
     *
     * @param  array<string, mixed>  $aiStructure
     * @param  array<string, mixed>  $deterministicStructure
     * @param  array{
     *   units: array<int, array{page_number: int, title: string}>,
     *   lessons: array<int, array{page_number: int, title: string}>
     * }  $candidates
     * @return array{structure: array<string, mixed>, merge_actions: array<int, string>}
     */
    public function merge(array $aiStructure, array $deterministicStructure, array $candidates, int $totalPages): array
    {
        $mergeActions = [];
        $mergedUnits = [];

        $deterministicUnits = $deterministicStructure['children'] ?? [];
        $aiUnits = $aiStructure['children'] ?? [];

        foreach ($deterministicUnits as $unitIndex => $deterministicUnit) {
            if (! is_array($deterministicUnit)) {
                continue;
            }

            $aiUnit = $this->findMatchingUnit($deterministicUnit, $aiUnits);
            $unitKey = (string) ($deterministicUnit['key'] ?? $this->makeKey('unit', $unitIndex + 1));

            if ($aiUnit === null) {
                $mergedUnits[] = $deterministicUnit;
                $mergeActions[] = 'restored_missing_unit:'.$unitKey;

                continue;
            }

            $refinedTitle = $this->preferLongerTitle(
                (string) ($deterministicUnit['title'] ?? ''),
                (string) ($aiUnit['title'] ?? '')
            );

            if ($refinedTitle !== ($deterministicUnit['title'] ?? '')) {
                $mergeActions[] = 'refined_unit_title:'.$unitKey;
            }

            $mergedLessons = $this->mergeLessons(
                $deterministicUnit,
                $aiUnit,
                $unitIndex,
                $mergeActions
            );

            $mergedUnits[] = [
                'key' => $unitKey,
                'type' => 'unit',
                'title' => $refinedTitle,
                'heading_page' => (int) ($deterministicUnit['heading_page'] ?? $deterministicUnit['start_page'] ?? 1),
                'start_page' => (int) ($deterministicUnit['start_page'] ?? $aiUnit['start_page'] ?? 1),
                'end_page' => (int) ($deterministicUnit['end_page'] ?? $aiUnit['end_page'] ?? $totalPages),
                'children' => $mergedLessons,
            ];
        }

        $structure = [
            'key' => 'book',
            'type' => 'book',
            'title' => $aiStructure['title'] ?? $deterministicStructure['title'] ?? 'كتاب',
            'start_page' => 1,
            'end_page' => $totalPages,
            'children' => $mergedUnits,
        ];

        $coverage = $this->coverage->validate($structure, $candidates, $totalPages);

        if (! $coverage['complete']) {
            $aiUnits = array_values(array_filter(
                $aiStructure['children'] ?? [],
                fn ($child) => is_array($child) && ($child['type'] ?? null) === 'unit'
            ));

            if ($mergedUnits === [] && $aiUnits !== []) {
                $mergeActions[] = 'fallback_to_ai_structure';
                $structure = $aiStructure;
            } else {
                $mergeActions[] = 'fallback_to_full_deterministic_structure';
                $structure = $deterministicStructure;
            }
        }

        return [
            'structure' => $structure,
            'merge_actions' => $mergeActions,
        ];
    }

    /**
     * @param  array<string, mixed>  $deterministicUnit
     * @param  array<int, array<string, mixed>>  $aiUnits
     * @return array<string, mixed>|null
     */
    private function findMatchingUnit(array $deterministicUnit, array $aiUnits): ?array
    {
        $targetPage = (int) ($deterministicUnit['heading_page'] ?? $deterministicUnit['start_page'] ?? 0);
        $targetTitle = ArabicTextService::normalizeArabicText((string) ($deterministicUnit['title'] ?? ''));

        foreach ($aiUnits as $aiUnit) {
            if (! is_array($aiUnit)) {
                continue;
            }

            $aiPage = (int) ($aiUnit['heading_page'] ?? $aiUnit['start_page'] ?? 0);

            if ($aiPage === $targetPage) {
                return $aiUnit;
            }
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($aiUnits as $aiUnit) {
            if (! is_array($aiUnit)) {
                continue;
            }

            $aiPage = (int) ($aiUnit['heading_page'] ?? $aiUnit['start_page'] ?? 0);
            $pageDistance = abs($aiPage - $targetPage);

            if ($pageDistance > 1) {
                continue;
            }

            $titleScore = ArabicTextService::textSimilarity(
                $targetTitle,
                (string) ($aiUnit['title'] ?? '')
            );

            if ($titleScore < 0.55) {
                continue;
            }

            $score = $titleScore + ($pageDistance === 0 ? 0.5 : 0.1);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $aiUnit;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $deterministicUnit
     * @param  array<string, mixed>  $aiUnit
     * @param  array<int, string>  $mergeActions
     * @return array<int, array<string, mixed>>
     */
    private function mergeLessons(
        array $deterministicUnit,
        array $aiUnit,
        int $unitIndex,
        array &$mergeActions,
    ): array {
        $mergedLessons = [];
        $deterministicLessons = $deterministicUnit['children'] ?? [];
        $aiLessons = $aiUnit['children'] ?? [];

        foreach ($deterministicLessons as $lessonIndex => $deterministicLesson) {
            if (! is_array($deterministicLesson) || ($deterministicLesson['type'] ?? null) !== 'lesson') {
                continue;
            }

            $lessonKey = (string) ($deterministicLesson['key'] ?? $this->makeKey('lesson-'.($unitIndex + 1), $lessonIndex + 1));
            $aiLesson = $this->findMatchingLesson($deterministicLesson, $aiLessons);

            if ($aiLesson === null) {
                $mergedLessons[] = $deterministicLesson;
                $mergeActions[] = 'restored_missing_lesson:'.$lessonKey;

                continue;
            }

            $refinedTitle = $this->preferLongerTitle(
                (string) ($deterministicLesson['title'] ?? ''),
                (string) ($aiLesson['title'] ?? '')
            );

            if ($refinedTitle !== ($deterministicLesson['title'] ?? '')) {
                $mergeActions[] = 'refined_lesson_title:'.$lessonKey;
            }

            $mergedLessons[] = [
                'key' => $lessonKey,
                'type' => 'lesson',
                'title' => $refinedTitle,
                'heading_page' => (int) ($deterministicLesson['heading_page'] ?? $deterministicLesson['start_page'] ?? 1),
                'start_page' => (int) ($deterministicLesson['start_page'] ?? $aiLesson['start_page'] ?? 1),
                'end_page' => (int) ($deterministicLesson['end_page'] ?? $aiLesson['end_page'] ?? 1),
                'children' => [],
            ];
        }

        return $mergedLessons;
    }

    /**
     * @param  array<string, mixed>  $deterministicLesson
     * @param  array<int, array<string, mixed>>  $aiLessons
     * @return array<string, mixed>|null
     */
    private function findMatchingLesson(array $deterministicLesson, array $aiLessons): ?array
    {
        $targetPage = (int) ($deterministicLesson['heading_page'] ?? $deterministicLesson['start_page'] ?? 0);
        $targetTitle = ArabicTextService::normalizeArabicText((string) ($deterministicLesson['title'] ?? ''));

        foreach ($aiLessons as $aiLesson) {
            if (! is_array($aiLesson) || ($aiLesson['type'] ?? null) !== 'lesson') {
                continue;
            }

            $aiPage = (int) ($aiLesson['heading_page'] ?? $aiLesson['start_page'] ?? 0);

            if ($aiPage === $targetPage) {
                return $aiLesson;
            }
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($aiLessons as $aiLesson) {
            if (! is_array($aiLesson) || ($aiLesson['type'] ?? null) !== 'lesson') {
                continue;
            }

            $aiPage = (int) ($aiLesson['heading_page'] ?? $aiLesson['start_page'] ?? 0);
            $pageDistance = abs($aiPage - $targetPage);

            if ($pageDistance > 1) {
                continue;
            }

            $titleScore = ArabicTextService::textSimilarity(
                $targetTitle,
                (string) ($aiLesson['title'] ?? '')
            );

            if ($titleScore < 0.55) {
                continue;
            }

            $score = $titleScore + ($pageDistance === 0 ? 0.5 : 0.1);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $aiLesson;
            }
        }

        return $best;
    }

    private function preferLongerTitle(string $deterministicTitle, string $aiTitle): string
    {
        $deterministicTitle = trim($deterministicTitle);
        $aiTitle = trim($aiTitle);

        if ($aiTitle === '') {
            return $deterministicTitle;
        }

        if ($deterministicTitle === '') {
            return $aiTitle;
        }

        if (mb_strlen($aiTitle) > mb_strlen($deterministicTitle)
            && ArabicTextService::textSimilarity($deterministicTitle, $aiTitle) >= 0.35) {
            return $aiTitle;
        }

        return $deterministicTitle;
    }

    private function makeKey(string $prefix, int $index): string
    {
        return "{$prefix}-{$index}";
    }
}
