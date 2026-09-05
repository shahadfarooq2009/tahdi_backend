<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE game_sessions ADD COLUMN IF NOT EXISTS challenge_mode TEXT CHECK (challenge_mode IN ('family', 'school'))");
        DB::statement('ALTER TABLE game_sessions ADD COLUMN IF NOT EXISTS class_name TEXT');

        DB::statement('
            CREATE TABLE IF NOT EXISTS public.game_session_questions (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                game_session_id UUID NOT NULL REFERENCES public.game_sessions(id) ON DELETE CASCADE,
                question_id UUID NOT NULL REFERENCES public.questions(id) ON DELETE CASCADE,
                subject_id UUID REFERENCES public.subjects(id) ON DELETE SET NULL,
                row_position INTEGER NOT NULL,
                col_position INTEGER NOT NULL,
                points_value INTEGER NOT NULL DEFAULT 100,
                created_at TIMESTAMPTZ DEFAULT NOW(),
                UNIQUE (game_session_id, row_position, col_position),
                UNIQUE (game_session_id, question_id)
            )
        ');

        DB::statement('
            CREATE TABLE IF NOT EXISTS public.game_session_state (
                game_session_id UUID PRIMARY KEY REFERENCES public.game_sessions(id) ON DELETE CASCADE,
                mode TEXT NOT NULL CHECK (mode IN (\'family\', \'school\')),
                class_name TEXT,
                metadata JSONB NOT NULL DEFAULT \'{}\'::jsonb,
                board JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                visited_cells JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                unanswered_cells JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                active_team_index INTEGER NOT NULL DEFAULT 0,
                win_lines JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                processed_wins JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                used_bonus_cells JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                doubled_teams JSONB NOT NULL DEFAULT \'[]\'::jsonb,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_game_session_questions_session ON public.game_session_questions (game_session_id)');

        DB::statement('ALTER TABLE public.question_answers ALTER COLUMN team_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.game_session_state');
        DB::statement('DROP TABLE IF EXISTS public.game_session_questions');
        DB::statement('ALTER TABLE game_sessions DROP COLUMN IF EXISTS challenge_mode');
        DB::statement('ALTER TABLE game_sessions DROP COLUMN IF EXISTS class_name');
    }
};
