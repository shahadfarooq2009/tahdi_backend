<?php

namespace App\Services\Curriculum;

class ChunkingService
{
    /**
     * @param  array<int, array{page_number: int, content_text: string, normalized_text?: string}>  $pages
     * @param  array<string, mixed>  $structure
     * @return array<int, array<string, mixed>>
     */
    public function buildChunksFromStructure(array $pages, array $structure): array
    {
        /** @var array<int, array<string, mixed>> $chunks */
        $chunks = [];

        $this->walkStructure($structure, function (array $node, array $ancestors) use ($pages, &$chunks): void {
            if (! in_array($node['type'] ?? null, ['lesson', 'section'], true)) {
                return;
            }

            $unit = $this->findAncestorByType($ancestors, 'unit');
            $lesson = ($node['type'] ?? null) === 'lesson'
                ? $node
                : $this->findAncestorByType($ancestors, 'lesson');

            $startPage = (int) ($node['start_page'] ?? 0);
            $endPage = (int) ($node['end_page'] ?? 0);

            $pageContent = collect($pages)
                ->filter(fn ($page) => $page['page_number'] >= $startPage && $page['page_number'] <= $endPage)
                ->pluck('content_text')
                ->implode("\n\n");

            $normalizedContent = collect($pages)
                ->filter(fn ($page) => $page['page_number'] >= $startPage && $page['page_number'] <= $endPage)
                ->map(fn ($page) => $page['normalized_text'] ?? ArabicTextService::normalizeArabicText($page['content_text']))
                ->implode("\n\n");

            $pageContent = trim($pageContent);
            $normalizedContent = trim($normalizedContent);

            if ($pageContent === '') {
                return;
            }

            $chunks[] = [
                'unit_key' => $unit['key'] ?? null,
                'lesson_key' => $lesson['key'] ?? $node['key'],
                'section_key' => ($node['type'] ?? null) === 'section' ? $node['key'] : null,
                'unit_title' => $unit['title'] ?? null,
                'lesson_title' => $lesson['title'] ?? $node['title'],
                'section_title' => ($node['type'] ?? null) === 'section' ? $node['title'] : null,
                'source_page_start' => $startPage,
                'source_page_end' => $endPage,
                'content' => $pageContent,
                'normalized_content' => $normalizedContent,
                'token_estimate' => (int) ceil(mb_strlen($pageContent) / 4),
                'metadata' => [
                    'node_type' => $node['type'] ?? null,
                    'node_key' => $node['key'] ?? null,
                    'node_title' => $node['title'] ?? null,
                    'heading_page' => $node['heading_page'] ?? null,
                ],
            ];
        });

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $structure
     * @param  callable(array<string, mixed>, array<int, array<string, mixed>>): void  $visitor
     * @param  array<int, array<string, mixed>>  $ancestors
     */
    public function walkStructure(array $structure, callable $visitor, array $ancestors = []): void
    {
        $visitor($structure, $ancestors);

        foreach ($structure['children'] ?? [] as $child) {
            if (! is_array($child)) {
                continue;
            }

            $this->walkStructure($child, $visitor, [...$ancestors, $structure]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $ancestors
     * @return array<string, mixed>|null
     */
    private function findAncestorByType(array $ancestors, string $type): ?array
    {
        foreach (array_reverse($ancestors) as $ancestor) {
            if (($ancestor['type'] ?? null) === $type) {
                return $ancestor;
            }
        }

        return null;
    }
}
