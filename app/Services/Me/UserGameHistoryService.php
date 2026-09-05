<?php

namespace App\Services\Me;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserGameHistoryService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(string $userId, int $limit = 50): array
    {
        $historyClearedAt = $this->resolveHistoryClearedAt($userId);

        $sessionsQuery = DB::table('game_sessions as gs')
            ->leftJoin('game_session_state as gss', 'gss.game_session_id', '=', 'gs.id')
            ->where('gs.host_id', $userId)
            ->whereIn('gs.status', ['completed', 'in_progress', 'waiting']);

        if ($historyClearedAt !== null) {
            $sessionsQuery->where('gs.created_at', '>=', $historyClearedAt);
        }

        $sessions = $sessionsQuery
            ->orderByDesc(DB::raw('COALESCE(gs.ended_at, gs.updated_at, gs.created_at)'))
            ->limit($limit)
            ->select([
                'gs.id',
                'gs.session_code',
                'gs.class_name',
                'gs.status',
                'gs.challenge_mode',
                'gs.created_at',
                'gs.started_at',
                'gs.ended_at',
                'gs.winner_team_id',
                'gss.metadata as state_metadata',
                'gss.mode as state_mode',
            ])
            ->get();

        if ($sessions->isEmpty()) {
            return [];
        }

        $sessionIds = $sessions->pluck('id')->all();

        $questionCounts = DB::table('game_session_questions')
            ->whereIn('game_session_id', $sessionIds)
            ->select('game_session_id', DB::raw('COUNT(*) as question_count'))
            ->groupBy('game_session_id')
            ->pluck('question_count', 'game_session_id');

        $subjectsBySession = DB::table('game_session_subjects as gss')
            ->join('subjects as s', 's.id', '=', 'gss.subject_id')
            ->whereIn('gss.game_session_id', $sessionIds)
            ->select([
                'gss.game_session_id',
                's.id as subject_id',
                's.name as subject_name',
                'gss.subject_order',
            ])
            ->orderBy('gss.subject_order')
            ->get()
            ->groupBy('game_session_id');

        $teamsBySession = DB::table('team_game_progress as tgp')
            ->join('teams as t', 't.id', '=', 'tgp.team_id')
            ->whereIn('tgp.game_session_id', $sessionIds)
            ->select([
                'tgp.game_session_id',
                't.id as team_id',
                't.name as team_name',
                't.avatar_url',
                't.color',
                'tgp.current_score',
                'tgp.questions_answered',
                'tgp.correct_answers',
            ])
            ->orderBy('tgp.joined_at')
            ->get()
            ->groupBy('game_session_id');

        return $sessions->map(function ($session) use ($subjectsBySession, $teamsBySession, $questionCounts) {
            $sessionId = (string) $session->id;
            $metadata = $this->decodeMetadata($session->state_metadata ?? null);
            $challengeMode = $session->challenge_mode ?: ($session->state_mode ?? 'school');
            $subjects = ($subjectsBySession[$sessionId] ?? collect())->values()->map(fn ($row) => [
                'id' => $row->subject_id,
                'name' => $row->subject_name,
            ])->all();
            $teams = ($teamsBySession[$sessionId] ?? collect())->values()->map(fn ($row) => [
                'id' => $row->team_id,
                'name' => $row->team_name,
                'avatar_url' => $row->avatar_url,
                'color' => $row->color,
                'current_score' => (int) $row->current_score,
                'questions_answered' => (int) $row->questions_answered,
                'correct_answers' => (int) $row->correct_answers,
            ])->all();

            return [
                'id' => $sessionId,
                'session_code' => $session->session_code,
                'class_name' => $session->class_name,
                'status' => $session->status,
                'challenge_mode' => $challengeMode,
                'created_at' => $session->created_at,
                'started_at' => $session->started_at ?? null,
                'ended_at' => $session->ended_at ?? null,
                'finished_at' => $session->ended_at ?? null,
                'winner_team_id' => $session->winner_team_id ?? null,
                'question_count' => (int) ($questionCounts[$sessionId] ?? 0),
                'metadata' => $metadata,
                'source_type' => $this->resolveSourceType($challengeMode, $metadata),
                'source_label' => $this->resolveSourceLabel($challengeMode, $metadata),
                'title' => $this->resolveTitle($session, $metadata, $subjects),
                'subjects' => $subjects,
                'teams' => $teams,
            ];
        })->values()->all();
    }

    public function clearForUser(string $userId): int
    {
        $hiddenCount = DB::table('game_sessions')
            ->where('host_id', $userId)
            ->whereIn('status', ['completed', 'in_progress', 'waiting'])
            ->count();

        if (Schema::hasTable('user_profiles') && Schema::hasColumn('user_profiles', 'game_history_cleared_at')) {
            DB::table('user_profiles')
                ->where('id', $userId)
                ->update(['game_history_cleared_at' => now()]);
        }

        return $hiddenCount;
    }

    private function resolveHistoryClearedAt(string $userId): ?string
    {
        if (! Schema::hasTable('user_profiles') || ! Schema::hasColumn('user_profiles', 'game_history_cleared_at')) {
            return null;
        }

        $value = DB::table('user_profiles')
            ->where('id', $userId)
            ->value('game_history_cleared_at');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array{id: string, name: string}>  $subjects
     */
    private function resolveTitle(object $session, array $metadata, array $subjects): string
    {
        $candidates = [
            $metadata['game_title'] ?? null,
            $metadata['play_set_title'] ?? null,
            $metadata['selected_subject'] ?? null,
            $session->class_name ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        $subjectNames = array_values(array_filter(array_map(
            fn (array $subject) => $subject['name'] ?? '',
            $subjects,
        )));

        if ($subjectNames !== []) {
            return implode('، ', $subjectNames);
        }

        return 'لعبة '.($session->session_code ?? '');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveSourceType(string $challengeMode, array $metadata): string
    {
        if ($challengeMode === 'family') {
            return 'family';
        }

        if (($metadata['question_source'] ?? null) === 'user_play_set') {
            return 'user_play_set';
        }

        if (! empty($metadata['game_id'])) {
            return 'school_curriculum';
        }

        if (($metadata['question_source'] ?? null) === 'excel_game') {
            return 'school_excel';
        }

        if (! empty($metadata['review_set_id'])) {
            return 'review_set';
        }

        return 'school_admin';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveSourceLabel(string $challengeMode, array $metadata): string
    {
        return match ($this->resolveSourceType($challengeMode, $metadata)) {
            'family' => 'تحدي العائلات',
            'user_play_set' => 'لعبتي بالذكاء الاصطناعي',
            'school_curriculum' => 'لعبة المنهج',
            'school_excel' => 'أسئلة المدرسة (Excel)',
            'review_set' => 'مراجعة الكتاب',
            default => 'تحدي المدارس',
        };
    }
}
