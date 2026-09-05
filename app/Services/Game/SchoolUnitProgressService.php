<?php

namespace App\Services\Game;

use App\Models\Question;
use App\Models\SchoolCourse;
use App\Models\SchoolGame;
use App\Models\SchoolUnit;
use App\Models\UserGameProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SchoolUnitProgressService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUnitsForSubject(
        string $subjectId,
        ?string $dbStage,
        ?string $dbGrade,
        ?string $userId,
    ): array {
        if (! $this->isEnabled()) {
            return [];
        }

        $units = SchoolUnit::query()
            ->where('subject_id', $subjectId)
            ->when($this->hasCourseColumn(), fn ($query) => $query->whereNull('course_id'))
            ->when($dbStage, fn ($query) => $query->where('educational_stage', $dbStage))
            ->when($dbGrade, fn ($query) => $query->where('grade', $dbGrade))
            ->orderBy('display_order')
            ->orderBy('unit_number')
            ->get();

        return $this->mapUnits($units, $userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUnitsForCourse(string $courseId, ?string $userId): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $units = SchoolUnit::query()
            ->where('course_id', $courseId)
            ->orderBy('display_order')
            ->orderBy('unit_number')
            ->get();

        return $this->mapUnits($units, $userId);
    }

    /**
     * @return string[]
     */
    public function parentSubjectIdsWithPlayableCourses(?string $dbGrade): array
    {
        if (! $this->isEnabled() || ! Schema::hasTable('school_courses') || ! $this->hasCourseColumn()) {
            return [];
        }

        $unitsQuery = SchoolUnit::query()->whereNotNull('course_id');

        if ($dbGrade) {
            $unitsQuery->where('grade', $dbGrade);
        }

        $units = $unitsQuery->get(['id', 'course_id']);

        if ($units->isEmpty()) {
            return [];
        }

        $playableUnitIds = $this->playableUnitIds($units->pluck('id')->all());
        $courseIds = $units
            ->filter(fn (SchoolUnit $unit) => in_array($unit->id, $playableUnitIds, true))
            ->pluck('course_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($courseIds === []) {
            return [];
        }

        return SchoolCourse::query()
            ->whereIn('id', $courseIds)
            ->pluck('parent_subject_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return string[]
     */
    public function courseIdsWithPlayableUnits(string $parentSubjectId, ?string $dbGrade): array
    {
        if (! $this->isEnabled() || ! Schema::hasTable('school_courses') || ! $this->hasCourseColumn()) {
            return [];
        }

        $courseIds = SchoolCourse::query()
            ->where('parent_subject_id', $parentSubjectId)
            ->when($dbGrade, fn ($query) => $query->where('grade', $dbGrade))
            ->pluck('id')
            ->all();

        if ($courseIds === []) {
            return [];
        }

        $units = SchoolUnit::query()
            ->whereIn('course_id', $courseIds)
            ->when($dbGrade, fn ($query) => $query->where('grade', $dbGrade))
            ->get(['id', 'course_id']);

        if ($units->isEmpty()) {
            return [];
        }

        $playableUnitIds = $this->playableUnitIds($units->pluck('id')->all());

        return $units
            ->filter(fn (SchoolUnit $unit) => in_array($unit->id, $playableUnitIds, true))
            ->pluck('course_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, SchoolUnit>  $units
     * @return array<int, array<string, mixed>>
     */
    private function mapUnits($units, ?string $userId): array
    {
        if ($units->isEmpty()) {
            return [];
        }

        $unitIds = $units->pluck('id')->all();
        $gamesByUnit = $this->gamesGroupedByUnit($unitIds);
        $completedGameIds = $userId ? $this->completedGameIdsForUser($userId) : [];

        return $units
            ->map(function (SchoolUnit $unit) use ($gamesByUnit, $completedGameIds) {
                $games = $gamesByUnit[$unit->id] ?? collect();
                $totalGames = $games->count();
                $completedGames = $games
                    ->filter(fn (SchoolGame $game) => in_array($game->id, $completedGameIds, true))
                    ->count();
                $remainingGames = max(0, $totalGames - $completedGames);

                return [
                    'id' => $unit->id,
                    'chapter_id' => $unit->chapter_id,
                    'title' => $unit->title,
                    'unit_number' => $unit->unit_number,
                    'total_games' => $totalGames,
                    'completed_games' => $completedGames,
                    'remaining_games' => $remainingGames,
                    'is_completed' => $totalGames > 0 && $remainingGames === 0,
                    'content_type' => 'school_unit',
                ];
            })
            ->filter(fn (array $unit) => ($unit['total_games'] ?? 0) > 0)
            ->values()
            ->all();
    }

    /**
     * @param  string[]|null  $subjectIds
     * @return string[]
     */
    public function subjectIdsWithPlayableUnits(
        ?string $dbStage,
        ?string $dbGrade,
        ?array $subjectIds = null,
    ): array {
        if (! $this->isEnabled()) {
            return [];
        }

        $unitsQuery = SchoolUnit::query()
            ->when($this->hasCourseColumn(), fn ($query) => $query->whereNull('course_id'))
            ->when($dbStage, fn ($query) => $query->where('educational_stage', $dbStage))
            ->when($dbGrade, fn ($query) => $query->where('grade', $dbGrade));

        if (is_array($subjectIds) && $subjectIds !== []) {
            $unitsQuery->whereIn('subject_id', $subjectIds);
        }

        $units = $unitsQuery->get(['id', 'subject_id']);

        if ($units->isEmpty()) {
            return [];
        }

        $playableUnitIds = $this->playableUnitIds($units->pluck('id')->all());

        return $units
            ->filter(fn (SchoolUnit $unit) => in_array($unit->id, $playableUnitIds, true))
            ->pluck('subject_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listGamesForUnit(string $unitId, ?string $userId): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $unit = SchoolUnit::query()->find($unitId);

        if (! $unit) {
            return [];
        }

        $completedGameIds = $userId ? $this->completedGameIdsForUser($userId) : [];

        $playableGameIds = $this->playableGameIdsForUnit($unitId);

        return SchoolGame::query()
            ->where('unit_id', $unitId)
            ->whereIn('id', $playableGameIds)
            ->orderBy('display_order')
            ->orderBy('game_number')
            ->get()
            ->map(fn (SchoolGame $game) => [
                'id' => $game->id,
                'unit_id' => $game->unit_id,
                'title' => $game->title,
                'game_number' => $game->game_number,
                'is_completed' => in_array($game->id, $completedGameIds, true),
            ])
            ->all();
    }

    public function markGameCompleted(
        string $userId,
        string $gameId,
        ?string $gameSessionId = null,
        ?int $score = null,
    ): void {
        if (! $this->isEnabled()) {
            return;
        }

        UserGameProgress::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'game_id' => $gameId,
            ],
            [
                'game_session_id' => $gameSessionId,
                'score' => $score,
                'completed_at' => now(),
            ],
        );
    }

    public function isGameCompleted(string $userId, string $gameId): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return UserGameProgress::query()
            ->where('user_id', $userId)
            ->where('game_id', $gameId)
            ->exists();
    }

    /**
     * @param  string[]  $unitIds
     * @return array<string, Collection<int, SchoolGame>>
     */
    private function gamesGroupedByUnit(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $games = SchoolGame::query()
            ->whereIn('unit_id', $unitIds)
            ->orderBy('display_order')
            ->orderBy('game_number')
            ->get();

        $playableGameIds = $this->playableGameIds($games->pluck('id')->all());

        return $games
            ->filter(fn (SchoolGame $game) => in_array($game->id, $playableGameIds, true))
            ->groupBy('unit_id')
            ->all();
    }

    /**
     * @param  string[]  $gameIds
     * @return string[]
     */
    private function playableGameIds(array $gameIds): array
    {
        if ($gameIds === []) {
            return [];
        }

        return $this->playableQuestionsQuery()
            ->whereIn('game_id', $gameIds)
            ->distinct()
            ->pluck('game_id')
            ->all();
    }

    /**
     * @return string[]
     */
    private function playableGameIdsForUnit(string $unitId): array
    {
        $gameIds = SchoolGame::query()
            ->where('unit_id', $unitId)
            ->pluck('id')
            ->all();

        return $this->playableGameIds($gameIds);
    }

    /**
     * @param  string[]  $unitIds
     * @return string[]
     */
    private function playableUnitIds(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $games = SchoolGame::query()
            ->whereIn('unit_id', $unitIds)
            ->get(['id', 'unit_id']);

        if ($games->isEmpty()) {
            return [];
        }

        $playableGameIds = $this->playableGameIds($games->pluck('id')->all());

        return $games
            ->filter(fn (SchoolGame $game) => in_array($game->id, $playableGameIds, true))
            ->pluck('unit_id')
            ->unique()
            ->values()
            ->all();
    }

    private function playableQuestionsQuery(): Builder
    {
        return Question::query()
            ->whereNotNull('game_id')
            ->whereNull('category_id')
            ->where('is_deleted', false)
            ->where('approval_status', 'approved')
            ->where(function (Builder $query) {
                $query->where('is_active', true)->orWhereNull('is_active');
            });
    }

    /**
     * @return string[]
     */
    private function completedGameIdsForUser(string $userId): array
    {
        return UserGameProgress::query()
            ->where('user_id', $userId)
            ->pluck('game_id')
            ->all();
    }

    private function isEnabled(): bool
    {
        return Schema::hasTable('school_units')
            && Schema::hasTable('school_games')
            && Schema::hasTable('user_game_progress');
    }

    private function hasCourseColumn(): bool
    {
        return Schema::hasColumn('school_units', 'course_id');
    }
}
