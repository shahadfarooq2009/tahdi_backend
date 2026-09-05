<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$statements = [
    "ALTER TABLE public.questions ADD COLUMN IF NOT EXISTS question_source TEXT NOT NULL DEFAULT 'manual' CHECK (question_source IN ('manual', 'textbook_ai'))",
    'ALTER TABLE public.questions ADD COLUMN IF NOT EXISTS textbook_id UUID REFERENCES public.textbooks(id) ON DELETE SET NULL',
    'ALTER TABLE public.questions ADD COLUMN IF NOT EXISTS ai_generated BOOLEAN NOT NULL DEFAULT FALSE',
    'CREATE INDEX IF NOT EXISTS idx_questions_source ON public.questions(question_source) WHERE is_deleted = FALSE',
    'CREATE INDEX IF NOT EXISTS idx_questions_textbook ON public.questions(textbook_id) WHERE textbook_id IS NOT NULL',
];

foreach ($statements as $sql) {
    DB::statement($sql);
    echo "applied: {$sql}\n";
}

echo 'questions_has_question_source='.(Schema::hasColumn('questions', 'question_source') ? 'yes' : 'no').PHP_EOL;
echo 'questions_has_textbook_id='.(Schema::hasColumn('questions', 'textbook_id') ? 'yes' : 'no').PHP_EOL;
echo 'questions_has_ai_generated='.(Schema::hasColumn('questions', 'ai_generated') ? 'yes' : 'no').PHP_EOL;
