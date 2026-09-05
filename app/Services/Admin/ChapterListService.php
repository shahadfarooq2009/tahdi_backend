<?php

namespace App\Services\Admin;

use App\Models\Chapter;

class ChapterListService
{
    /**
     * @param  array{subject_id?: string|null, created_by?: string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $query = Chapter::query()
            ->where(function ($builder) {
                $builder->where('is_deleted', false)->orWhereNull('is_deleted');
            });

        if (! empty($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }

        if (! empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        return $query
            ->orderBy('chapter_number')
            ->orderBy('name')
            ->get()
            ->map(fn (Chapter $chapter) => $chapter->toArray())
            ->all();
    }
}
