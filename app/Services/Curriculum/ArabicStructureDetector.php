<?php

namespace App\Services\Curriculum;

class ArabicStructureDetector
{
    private const UNIT_PATTERN = '/(?:^|\n|\R)\s*(الوحدة\s*(?:الأولى|الثانية|الثالثة|الرابعة|الخامسة|السادسة|السابعة|الثامنة|التاسعة|العاشرة|\d+)|الوحده\s*(?:الأولى|الثانية|الثالثة|الرابعة|الخامسة|السادسة|السابعة|الثامنة|التاسعة|العاشرة|\d+)|الفصل\s*(?:الأول|الثاني|الثالث|الرابع|الخامس|السادس|السابع|الثامن|التاسع|العاشر|\d+))(?:\s*[:\-–—]\s*|\s+)([^\n\r]{0,120})?/u';

    private const LESSON_PATTERN = '/(?:^|\n|\R)\s*(الدرس\s*(?:الأول|الثاني|الثالث|الرابع|الخامس|السادس|السابع|الثامن|التاسع|العاشر|الحادي\s*عشر|الثاني\s*عشر|\d+))(?:\s*[:\-–—]\s*|\s+)([^\n\r]{0,120})?/u';

    /**
     * @param  array<int, array{page_number: int, content_text: string, normalized_text?: string}>  $pages
     * @return array{
     *   units: array<int, array{page_number: int, title: string, kind: string}>,
     *   lessons: array<int, array{page_number: int, title: string, kind: string, unit_page: int|null}>
     * }
     */
    public function detectCandidates(array $pages): array
    {
        /** @var array<int, array{page_number: int, title: string, kind: string}> $units */
        $units = [];
        /** @var array<int, array{page_number: int, title: string, kind: string}> $lessons */
        $lessons = [];

        foreach ($pages as $page) {
            $pageNumber = (int) $page['page_number'];
            $content = (string) ($page['content_text'] ?? '');

            foreach ($this->extractMatches($content, self::UNIT_PATTERN, 'unit') as $match) {
                $units[] = [
                    'page_number' => $pageNumber,
                    'title' => $match,
                    'kind' => 'unit',
                ];
            }

            foreach ($this->extractMatches($content, self::LESSON_PATTERN, 'lesson') as $match) {
                $lessons[] = [
                    'page_number' => $pageNumber,
                    'title' => $match,
                    'kind' => 'lesson',
                ];
            }
        }

        $units = $this->deduplicateCandidates($units);
        $lessons = $this->deduplicateCandidates($lessons);
        $lessons = $this->attachLessonsToUnits($units, $lessons);

        return [
            'units' => $units,
            'lessons' => $lessons,
        ];
    }

    /**
     * @param  array{
     *   units: array<int, array{page_number: int, title: string, kind: string}>,
     *   lessons: array<int, array{page_number: int, title: string, kind: string, unit_page?: int|null}>
     * }  $candidates
     * @return array<string, mixed>
     */
    public function buildStructure(string $bookTitle, array $candidates, int $totalPages): array
    {
        $units = $candidates['units'] ?? [];
        $lessons = $candidates['lessons'] ?? [];

        if ($units === []) {
            return $this->buildEmptyBookStructure($bookTitle, $totalPages);
        }

        $unitMarkers = $units;

        $unitNodes = [];

        foreach ($unitMarkers as $unitIndex => $unit) {
            $nextUnitPage = $unitMarkers[$unitIndex + 1]['page_number'] ?? ($totalPages + 1);
            $unitStart = (int) $unit['page_number'];
            $unitEnd = max($unitStart, $nextUnitPage - 1);

            $unitLessons = array_values(array_filter(
                $lessons,
                fn (array $lesson) => (int) $lesson['page_number'] >= $unitStart
                    && (int) $lesson['page_number'] < $nextUnitPage
            ));

            $lessonMarkers = $unitLessons !== []
                ? $unitLessons
                : [['page_number' => $unitStart, 'title' => 'الدرس '.($unitIndex + 1), 'kind' => 'lesson']];

            $lessonNodes = [];

            foreach ($lessonMarkers as $lessonIndex => $lesson) {
                $lessonPage = (int) $lesson['page_number'];
                $nextLessonPage = $lessonMarkers[$lessonIndex + 1]['page_number'] ?? $nextUnitPage;
                $lessonStart = $lessonIndex === 0 ? $unitStart : $lessonPage;
                $lessonEnd = max($lessonStart, $nextLessonPage - 1);

                $lessonNodes[] = [
                    'key' => $this->makeKey('lesson-'.($unitIndex + 1), $lessonIndex + 1),
                    'type' => 'lesson',
                    'title' => (string) $lesson['title'],
                    'heading_page' => $lessonPage,
                    'start_page' => $lessonStart,
                    'end_page' => $lessonEnd,
                    'children' => [],
                ];
            }

            $unitNodes[] = [
                'key' => $this->makeKey('unit', $unitIndex + 1),
                'type' => 'unit',
                'title' => (string) $unit['title'],
                'heading_page' => $unitStart,
                'start_page' => $unitStart,
                'end_page' => $unitEnd,
                'children' => $lessonNodes,
            ];
        }

        return [
            'key' => 'book',
            'type' => 'book',
            'title' => $bookTitle,
            'start_page' => 1,
            'end_page' => $totalPages,
            'children' => $unitNodes,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractMatches(string $content, string $pattern, string $kind): array
    {
        if (! preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $results = [];

        foreach ($matches as $match) {
            $heading = trim((string) ($match[0] ?? ''));
            $heading = preg_replace('/\s+/u', ' ', $heading) ?? $heading;

            if ($heading === '') {
                continue;
            }

            if (isset($match[2]) && trim((string) $match[2]) !== '') {
                $suffix = trim((string) $match[2]);
                if (! str_contains($heading, $suffix)) {
                    $heading = rtrim($heading, ':').': '.$suffix;
                }
            }

            if ($this->isValidHeading($heading, $kind)) {
                $results[] = $heading;
            }
        }

        return $results;
    }

    private function isValidHeading(string $heading, string $kind): bool
    {
        $length = mb_strlen($heading);

        if ($length < 4 || $length > 160) {
            return false;
        }

        return match ($kind) {
            'unit' => (bool) preg_match('/الوحدة|الوحده|الفصل/u', $heading),
            'lesson' => (bool) preg_match('/الدرس/u', $heading),
            default => false,
        };
    }

    /**
     * @param  array<int, array{page_number: int, title: string, kind: string}>  $items
     * @return array<int, array{page_number: int, title: string, kind: string}>
     */
    private function deduplicateCandidates(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $key = $item['page_number'].'|'.ArabicTextService::normalizeArabicText($item['title']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $item;
        }

        usort($result, fn (array $a, array $b) => $a['page_number'] <=> $b['page_number']);

        return $result;
    }

    /**
     * @param  array<int, array{page_number: int, title: string, kind: string}>  $units
     * @param  array<int, array{page_number: int, title: string, kind: string}>  $lessons
     * @return array<int, array{page_number: int, title: string, kind: string, unit_page: int|null}>
     */
    private function attachLessonsToUnits(array $units, array $lessons): array
    {
        return array_map(function (array $lesson) use ($units): array {
            $unitPage = null;

            foreach ($units as $unit) {
                if ($lesson['page_number'] >= $unit['page_number']) {
                    $unitPage = $unit['page_number'];
                }
            }

            return [...$lesson, 'unit_page' => $unitPage];
        }, $lessons);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildEmptyBookStructure(string $bookTitle, int $totalPages): array
    {
        return [
            'key' => 'book',
            'type' => 'book',
            'title' => $bookTitle,
            'start_page' => 1,
            'end_page' => max(1, $totalPages),
            'children' => [],
        ];
    }

    private function makeKey(string $prefix, int $index): string
    {
        return "{$prefix}-{$index}";
    }
}
