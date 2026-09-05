<?php

namespace App\Services\Curriculum;

use App\Exceptions\ValidationException;
use Illuminate\Support\Str;

class TextbookPagePersistenceMapper
{
    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    public function mapForInsert(array $pages, string $textbookId): array
    {
        $rows = [];

        foreach ($pages as $page) {
            $pageNumber = (int) ($page['page_number'] ?? 0);
            $this->logPageFieldTypes($page, $pageNumber);
            $rows[] = $this->mapSinglePage($page, $textbookId, $pageNumber);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    public function mapSinglePage(array $page, string $textbookId, ?int $pageNumber = null): array
    {
        $pageNumber ??= (int) ($page['page_number'] ?? 0);

        $contentText = $this->requireStringOrNull($page['content_text'] ?? '', $pageNumber, 'content_text', allowEmpty: true);
        $normalizedText = $this->requireStringOrNull(
            $page['normalized_text'] ?? '',
            $pageNumber,
            'normalized_text',
            allowEmpty: true
        );
        $printedPageNumber = $this->normalizePrintedPageNumber($page['printed_page_number'] ?? null, $pageNumber);
        $extractionSource = $this->requireString($page['extraction_source'] ?? 'native', $pageNumber, 'extraction_source');
        $extractionQuality = $this->normalizeExtractionQuality($page['extraction_quality'] ?? null, $pageNumber);

        return [
            'id' => (string) Str::uuid(),
            'textbook_id' => $this->requireString($textbookId, $pageNumber, 'textbook_id'),
            'page_number' => $this->requireInteger($page['page_number'] ?? null, $pageNumber, 'page_number'),
            'content_text' => $contentText,
            'normalized_text' => $normalizedText,
            'printed_page_number' => $printedPageNumber,
            'extraction_source' => $extractionSource,
            'extraction_quality' => $extractionQuality,
            'created_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public function logPageFieldTypes(array $page, int $pageNumber): void
    {
        $fields = [
            'content_text',
            'normalized_text',
            'page_number',
            'printed_page_number',
            'extraction_source',
            'extraction_quality',
            'textbook_id',
        ];

        $types = [];

        foreach ($fields as $field) {
            $value = $field === 'textbook_id'
                ? ($page['textbook_id'] ?? null)
                : ($page[$field] ?? null);

            $types[$field] = $this->describeValueType($value);
        }

        logger()->debug('textbook_pages insert payload field types', [
            'page_number' => $pageNumber,
            'field_types' => $types,
        ]);
    }

    private function normalizeExtractionQuality(mixed $value, int $pageNumber): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if (! array_key_exists('score', $value)) {
                throw new ValidationException(
                    "Page {$pageNumber} extraction_quality expected float, array given without score key"
                );
            }

            return $this->requireFloat($value['score'], $pageNumber, 'extraction_quality.score');
        }

        if (is_int($value) || is_float($value)) {
            return $this->requireFloat($value, $pageNumber, 'extraction_quality');
        }

        if (is_string($value) && is_numeric($value)) {
            return $this->requireFloat((float) $value, $pageNumber, 'extraction_quality');
        }

        throw new ValidationException(
            'Page '.$pageNumber.' extraction_quality expected float, '.gettype($value).' given'
        );
    }

    private function normalizePrintedPageNumber(mixed $value, int $pageNumber): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            throw new ValidationException(
                'Page '.$pageNumber.' printed_page_number expected integer, '.gettype($value).' given'
            );
        }

        return $this->requireInteger($value, $pageNumber, 'printed_page_number');
    }

    private function requireString(mixed $value, int $pageNumber, string $field): string
    {
        if (! is_string($value)) {
            throw new ValidationException(
                "Page {$pageNumber} {$field} expected string, ".gettype($value).' given'
            );
        }

        return $value;
    }

    private function requireStringOrNull(
        mixed $value,
        int $pageNumber,
        string $field,
        bool $allowEmpty = false,
    ): string {
        if (! is_string($value)) {
            throw new ValidationException(
                "Page {$pageNumber} {$field} expected string, ".gettype($value).' given'
            );
        }

        if (! $allowEmpty && $value === '') {
            throw new ValidationException("Page {$pageNumber} {$field} cannot be empty");
        }

        return $value;
    }

    private function requireInteger(mixed $value, int $pageNumber, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        if (is_float($value) && (float) (int) $value === $value) {
            return (int) $value;
        }

        throw new ValidationException(
            "Page {$pageNumber} {$field} expected integer, ".gettype($value).' given'
        );
    }

    private function requireFloat(mixed $value, int $pageNumber, string $field): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new ValidationException(
            "Page {$pageNumber} {$field} expected float, ".gettype($value).' given'
        );
    }

    private function describeValueType(mixed $value): string
    {
        if (is_array($value)) {
            return 'array{'.implode(',', array_keys($value)).'}';
        }

        if (is_object($value)) {
            return 'object:'.get_class($value);
        }

        return gettype($value);
    }
}
