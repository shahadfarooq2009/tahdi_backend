<?php

namespace App\Services\Curriculum;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Chapter;
use App\Models\Textbook;
use Illuminate\Support\Facades\DB;

class UnitMappingService
{
    /**
     * @param  array<string, mixed>|null  $structure
     * @return array<int, array{unit_key: string, unit_title: string}>
     */
    public function extractUnitsFromStructure(?array $structure): array
    {
        if (! is_array($structure['children'] ?? null)) {
            return [];
        }

        return collect($structure['children'])
            ->filter(fn ($child) => ($child['type'] ?? null) === 'unit')
            ->map(fn ($unit) => [
                'unit_key' => (string) $unit['key'],
                'unit_title' => (string) ($unit['title'] ?? $unit['key']),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{unit_key: string, unit_title: string}>  $units
     */
    public function findExactUnitMatch(array $units, string $chapterName): ?array
    {
        $target = ArabicTextService::normalizeForComparison($chapterName);

        if ($target === '') {
            return null;
        }

        foreach ($units as $unit) {
            if (ArabicTextService::normalizeForComparison($unit['unit_title']) === $target) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveSchoolUnitContext(string $subjectId, ?array $metadata, ?string $actorUserId): array
    {
        $unitName = is_string($metadata['unit'] ?? null) ? $metadata['unit'] : null;
        $grade = is_string($metadata['grade'] ?? null) ? $metadata['grade'] : null;
        $educationalStage = is_string($metadata['educational_stage'] ?? null) ? $metadata['educational_stage'] : null;

        $chapter = $this->findChapterForUnitName($subjectId, $unitName);
        $textbook = $this->findActiveTextbookForSchool($subjectId, $grade, $educationalStage);

        if (! $textbook) {
            return [
                'textbook' => null,
                'chapter_id' => $chapter?->id,
                'chapter_name' => $unitName,
                'unit_key' => null,
                'unit_title' => null,
                'unit_candidates' => [],
            ];
        }

        $textbookArray = $textbook->toArray();
        $units = $this->extractUnitsFromStructure($textbookArray['approved_structure'] ?? null);

        if (! $chapter) {
            return [
                'textbook' => $textbookArray,
                'chapter_id' => null,
                'chapter_name' => $unitName,
                'unit_key' => null,
                'unit_title' => null,
                'unit_candidates' => $units,
            ];
        }

        $stored = DB::table('curriculum_unit_mappings')
            ->where('textbook_id', $textbook->id)
            ->where('chapter_id', $chapter->id)
            ->first();

        if ($stored) {
            return [
                'textbook' => $textbookArray,
                'chapter_id' => $chapter->id,
                'chapter_name' => $chapter->name,
                'unit_key' => $stored->unit_key,
                'unit_title' => $stored->unit_title,
                'mapping_type' => $stored->match_type,
                'unit_candidates' => $units,
            ];
        }

        $exactMatch = $this->findExactUnitMatch($units, $chapter->name);

        if ($exactMatch && $actorUserId) {
            $this->saveUnitMapping(
                $textbook->id,
                $chapter->id,
                $exactMatch['unit_key'],
                $exactMatch['unit_title'],
                'exact_title',
                $actorUserId
            );
        }

        if ($exactMatch) {
            return [
                'textbook' => $textbookArray,
                'chapter_id' => $chapter->id,
                'chapter_name' => $chapter->name,
                'unit_key' => $exactMatch['unit_key'],
                'unit_title' => $exactMatch['unit_title'],
                'mapping_type' => 'exact_title',
                'unit_candidates' => $units,
            ];
        }

        return [
            'textbook' => $textbookArray,
            'chapter_id' => $chapter->id,
            'chapter_name' => $chapter->name,
            'unit_key' => null,
            'unit_title' => null,
            'unit_candidates' => $units,
        ];
    }

    private function findChapterForUnitName(string $subjectId, ?string $unitName): ?Chapter
    {
        if (! $unitName) {
            return null;
        }

        $target = ArabicTextService::normalizeForComparison($unitName);

        return Chapter::query()
            ->where('subject_id', $subjectId)
            ->get()
            ->first(fn (Chapter $chapter) => ArabicTextService::normalizeForComparison($chapter->name) === $target);
    }

    private function findActiveTextbookForSchool(string $subjectId, ?string $grade, ?string $educationalStage): ?Textbook
    {
        $query = Textbook::query()
            ->where('subject_id', $subjectId)
            ->where('structure_status', 'approved')
            ->where('processing_status', 'ready')
            ->orderByDesc('created_at');

        if ($grade) {
            $query->where('grade', $grade);
        }

        if ($educationalStage) {
            $query->where('academic_stage', $educationalStage);
        }

        return $query->first();
    }

    private function saveUnitMapping(
        string $textbookId,
        string $chapterId,
        string $unitKey,
        ?string $unitTitle,
        string $matchType,
        ?string $actorUserId,
    ): void {
        DB::table('curriculum_unit_mappings')->upsert(
            [[
                'textbook_id' => $textbookId,
                'chapter_id' => $chapterId,
                'unit_key' => $unitKey,
                'unit_title' => $unitTitle,
                'match_type' => $matchType,
                'created_by' => $actorUserId,
                'updated_at' => now(),
            ]],
            ['textbook_id', 'chapter_id'],
            ['unit_key', 'unit_title', 'match_type', 'updated_at']
        );
    }

    /**
     * @return array<int, object>
     */
    public function listUnitMappings(string $textbookId): array
    {
        return DB::table('curriculum_unit_mappings as mappings')
            ->leftJoin('chapters', 'chapters.id', '=', 'mappings.chapter_id')
            ->where('mappings.textbook_id', $textbookId)
            ->orderBy('mappings.created_at')
            ->select([
                'mappings.*',
                'chapters.id as chapter_join_id',
                'chapters.name as chapter_name',
                'chapters.chapter_number as chapter_number',
                'chapters.subject_id as chapter_subject_id',
            ])
            ->get()
            ->map(function ($row) {
                return (object) [
                    'id' => $row->id,
                    'textbook_id' => $row->textbook_id,
                    'chapter_id' => $row->chapter_id,
                    'unit_key' => $row->unit_key,
                    'unit_title' => $row->unit_title,
                    'match_type' => $row->match_type,
                    'created_by' => $row->created_by,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'chapters' => $row->chapter_join_id ? (object) [
                        'id' => $row->chapter_join_id,
                        'name' => $row->chapter_name,
                        'chapter_number' => $row->chapter_number,
                        'subject_id' => $row->chapter_subject_id,
                    ] : null,
                ];
            })
            ->all();
    }

    /**
     * @return object
     */
    public function upsertUnitMapping(string $textbookId, string $chapterId, string $unitKey, string $actorUserId): object
    {
        $textbook = Textbook::query()->find($textbookId);

        if (! $textbook) {
            throw new NotFoundException('Textbook not found');
        }

        $units = $this->extractUnitsFromStructure($textbook->approved_structure);
        $unit = collect($units)->firstWhere('unit_key', $unitKey);

        if (! $unit) {
            throw new ValidationException('unit_key does not exist in the approved textbook structure');
        }

        $chapter = Chapter::query()->find($chapterId);

        if (! $chapter) {
            throw new NotFoundException('Chapter not found');
        }

        if ($textbook->subject_id && $chapter->subject_id !== $textbook->subject_id) {
            throw new ValidationException('chapter_id does not belong to the textbook subject');
        }

        $this->saveUnitMapping(
            $textbookId,
            $chapterId,
            $unitKey,
            $unit['unit_title'],
            'manual',
            $actorUserId,
        );

        return DB::table('curriculum_unit_mappings')
            ->where('textbook_id', $textbookId)
            ->where('chapter_id', $chapterId)
            ->first();
    }
}
