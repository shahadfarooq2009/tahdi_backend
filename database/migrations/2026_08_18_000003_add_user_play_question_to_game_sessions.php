<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.game_session_questions ADD COLUMN IF NOT EXISTS user_play_question_id UUID REFERENCES public.user_play_questions(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE public.game_session_questions ALTER COLUMN question_id DROP NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS idx_gsq_session_user_play_question ON public.game_session_questions (game_session_id, user_play_question_id) WHERE user_play_question_id IS NOT NULL');

        DB::statement('ALTER TABLE public.question_answers ADD COLUMN IF NOT EXISTS user_play_question_id UUID REFERENCES public.user_play_questions(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE public.question_answers ALTER COLUMN question_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_gsq_session_user_play_question');
        DB::statement('ALTER TABLE public.game_session_questions DROP COLUMN IF EXISTS user_play_question_id');
        DB::statement('ALTER TABLE public.question_answers DROP COLUMN IF EXISTS user_play_question_id');
    }
};
