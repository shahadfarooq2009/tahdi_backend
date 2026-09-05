<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE questions DROP CONSTRAINT IF EXISTS questions_question_source_check');
        DB::statement(
            "ALTER TABLE questions ADD CONSTRAINT questions_question_source_check CHECK (question_source IN ('manual', 'textbook_ai', 'excel'))"
        );
    }

    public function down(): void
    {
        DB::statement("UPDATE questions SET question_source = 'manual' WHERE question_source = 'excel'");
        DB::statement('ALTER TABLE questions DROP CONSTRAINT IF EXISTS questions_question_source_check');
        DB::statement(
            "ALTER TABLE questions ADD CONSTRAINT questions_question_source_check CHECK (question_source IN ('manual', 'textbook_ai'))"
        );
    }
};
