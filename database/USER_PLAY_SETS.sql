<?php

-- Run in Supabase SQL editor if artisan migrate is unavailable
CREATE TABLE IF NOT EXISTS public.user_play_sets (
    id UUID PRIMARY KEY,
    user_id UUID NOT NULL,
    title VARCHAR(500) NOT NULL,
    source_file_name VARCHAR(255),
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    question_count INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_user_play_sets_user_status ON public.user_play_sets (user_id, status);

CREATE TABLE IF NOT EXISTS public.user_play_questions (
    id UUID PRIMARY KEY,
    play_set_id UUID NOT NULL REFERENCES public.user_play_sets(id) ON DELETE CASCADE,
    question_text TEXT NOT NULL,
    answer_text TEXT NOT NULL,
    points_value SMALLINT NOT NULL DEFAULT 200,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    is_approved BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ,
    updated_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_user_play_questions_set ON public.user_play_questions (play_set_id);
