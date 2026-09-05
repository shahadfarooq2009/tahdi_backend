<?php

namespace App\Services\Curriculum;

class StructureCoverageValidator
{
    /**
     * @param  array<string, mixed>  $structure
     * @param  array{
     *   units: array<int, array{page_number: int, title: string}>,
     *   lessons: array<int, array{page_number: int, title: string}>
     * }  $deterministicCandidates
     * @return array{
     *   total_pages: int,
     *   covered_pages: array<int, int>,
     *   uncovered_pages: array<int, int>,
     *   coverage_percent: float,
     *   unit_count: int,
     *   lesson_count: int,
     *   expected_unit_count: int,
     *   expected_lesson_count: int,
     *   missing_units: array<int, array{page_number: int, title: string}>,
     *   missing_lessons: array<int, array{page_number: int, title: string}>,
     *   review_required: bool,
     *   complete: bool
     * }
     */
    public function validate(array $structure, array $deterministicCandidates, int $totalPages): array
    {
        $coveredPages = $this->collectCoveredPages($structure);
        $structureUnits = $this->extractUnits($structure);
        $structureLessons = $this->extractLessons($structure);

        $expectedUnits = $deterministicCandidates['units'] ?? [];
        $expectedLessons = $deterministicCandidates['lessons'] ?? [];

        $missingUnits = $this->findMissingCandidates($expectedUnits, $structureUnits);
        $missingLessons = $this->findMissingCandidates($expectedLessons, $structureLessons);

        $allPages = range(1, max(1, $totalPages));
        $uncoveredPages = array_values(array_diff($allPages, $coveredPages));
        $coveragePercent = $totalPages > 0
            ? round((count($coveredPages) / $totalPages) * 100, 1)
            : 0.0;

        $complete = $missingUnits === []
            && $missingLessons === []
            && $uncoveredPages === [];

        return [
            'total_pages' => $totalPages,
            'covered_pages' => $coveredPages,
            'uncovered_pages' => $uncoveredPages,
            'coverage_percent' => $coveragePercent,
            'unit_count' => count($structureUnits),
            'lesson_count' => count($structureLessons),
            'expected_unit_count' => count($expectedUnits),
            'expected_lesson_count' => count($expectedLessons),
            'missing_units' => $missingUnits,
            'missing_lessons' => $missingLessons,
            'review_required' => ! $complete,
            'complete' => $complete,
        ];
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return array<int, int>
     */
    public function collectCoveredPages(array $structure): array
    {
        $pages = [];

        $this->walkNodes($structure, function (array $node) use (&$pages): void {
            if (! in_array($node['type'] ?? null, ['unit', 'lesson', 'section'], true)) {
                return;
            }

            $start = (int) ($node['start_page'] ?? 0);
            $end = (int) ($node['end_page'] ?? 0);

            if ($start <= 0 || $end <= 0) {
                return;
            }

            foreach (range($start, $end) as $page) {
                $pages[$page] = $page;
            }
        });

        ksort($pages);

        return array_values($pages);
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return array<int, array{page_number: int, title: string}>
     */
    public function extractUnits(array $structure): array
    {
        $units = [];

        $this->walkNodes($structure, function (array $node) use (&$units): void {
            if (($node['type'] ?? null) !== 'unit') {
                return;
            }

            $units[] = [
                'page_number' => (int) ($node['heading_page'] ?? $node['start_page'] ?? 0),
                'title' => (string) ($node['title'] ?? ''),
            ];
        });

        return $units;
    }

    /**
     * @param  array<string, mixed>  $structure
     * @return array<int, array{page_number: int, title: string}>
     */
    public function extractLessons(array $structure): array
    {
        $lessons = [];

        $this->walkNodes($structure, function (array $node) use (&$lessons): void {
            if (($node['type'] ?? null) !== 'lesson') {
                return;
            }

            $lessons[] = [
                'page_number' => (int) ($node['heading_page'] ?? $node['start_page'] ?? 0),
                'title' => (string) ($node['title'] ?? ''),
            ];
        });

        return $lessons;
    }

    /**
     * @param  array<int, array{page_number: int, title: string}>  $expected
     * @param  array<int, array{page_number: int, title: string}>  $actual
     * @return array<int, array{page_number: int, title: string}>
     */
    private function findMissingCandidates(array $expected, array $actual): array
    {
        $missing = [];

        foreach ($expected as $candidate) {
            if (! $this->candidateExists($candidate, $actual)) {
                $missing[] = $candidate;
            }
        }

        return $missing;
    }

    /**
     * @param  array{page_number: int, title: string}  $candidate
     * @param  array<int, array{page_number: int, title: string}>  $actual
     */
    private function candidateExists(array $candidate, array $actual): bool
    {
        $candidatePage = (int) $candidate['page_number'];
        $candidateTitle = ArabicTextService::normalizeArabicText($candidate['title']);

        foreach ($actual as $item) {
            $pageDistance = abs((int) $item['page_number'] - $candidatePage);
            $titleSimilarity = ArabicTextService::textSimilarity(
                $candidateTitle,
                ArabicTextService::normalizeArabicText((string) $item['title'])
            );

            if ($pageDistance <= 1 || $titleSimilarity >= 0.55) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $structure
     * @param  callable(array<string, mixed>): void  $visitor
     */
    private function walkNodes(array $structure, callable $visitor): void
    {
        $visitor($structure);

        foreach ($structure['children'] ?? [] as $child) {
            if (is_array($child)) {
                $this->walkNodes($child, $visitor);
            }
        }
    }
}
