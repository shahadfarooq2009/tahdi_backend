<?php

namespace App\Services\Admin;

use App\Exceptions\ValidationException;
use App\Models\Chapter;
use Illuminate\Support\Str;

class ChapterService
{
    /**
     * @return array{chapter_id: string}
     */
    public function resolveChapter(
        string $actorUserId,
        string $subjectId,
        ?string $selectedChapterId = null,
        ?string $newChapterName = null,
    ): array {
        $chapterId = null;

        if ($selectedChapterId) {
            if (Str::startsWith($selectedChapterId, 'num:')) {
                $num = (int) Str::after($selectedChapterId, 'num:');

                if ($num <= 0) {
                    throw new ValidationException('Invalid chapter number');
                }

                $existing = Chapter::query()
                    ->where('subject_id', $subjectId)
                    ->where('chapter_number', $num)
                    ->first();

                if ($existing) {
                    $chapterId = $existing->id;
                } else {
                    $created = Chapter::query()->create([
                        'subject_id' => $subjectId,
                        'name' => "وحدة {$num}",
                        'chapter_number' => $num,
                        'created_by' => $actorUserId,
                    ]);
                    $chapterId = $created->id;
                }
            } else {
                $chapterId = $selectedChapterId;
            }
        }

        if (! $chapterId && $newChapterName) {
            $existing = Chapter::query()
                ->where('subject_id', $subjectId)
                ->where('created_by', $actorUserId)
                ->whereRaw('LOWER(name) = LOWER(?)', [$newChapterName])
                ->first();

            if ($existing) {
                $chapterId = $existing->id;
            } else {
                $created = Chapter::query()->create([
                    'subject_id' => $subjectId,
                    'name' => $newChapterName,
                    'chapter_number' => null,
                    'created_by' => $actorUserId,
                ]);
                $chapterId = $created->id;
            }
        }

        if (! $chapterId) {
            throw new ValidationException('Unable to resolve chapter');
        }

        return ['chapter_id' => $chapterId];
    }
}
