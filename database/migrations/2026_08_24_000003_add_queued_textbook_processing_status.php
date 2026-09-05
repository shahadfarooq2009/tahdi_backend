<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('textbooks')) {
            return;
        }

        DB::statement('ALTER TABLE textbooks DROP CONSTRAINT IF EXISTS textbooks_processing_status_check');

        DB::statement(<<<'SQL'
            ALTER TABLE textbooks ADD CONSTRAINT textbooks_processing_status_check
            CHECK (processing_status IN (
                'uploaded',
                'queued',
                'extracting',
                'analyzing_contents',
                'units_detected',
                'awaiting_unit_review',
                'units_approved',
                'generating_questions',
                'awaiting_question_review',
                'ready',
                'failed'
            ))
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('textbooks')) {
            return;
        }

        DB::table('textbooks')
            ->where('processing_status', 'queued')
            ->update(['processing_status' => 'uploaded']);

        DB::statement('ALTER TABLE textbooks DROP CONSTRAINT IF EXISTS textbooks_processing_status_check');

        DB::statement(<<<'SQL'
            ALTER TABLE textbooks ADD CONSTRAINT textbooks_processing_status_check
            CHECK (processing_status IN (
                'uploaded',
                'extracting',
                'analyzing_contents',
                'units_detected',
                'awaiting_unit_review',
                'units_approved',
                'generating_questions',
                'awaiting_question_review',
                'ready',
                'failed'
            ))
        SQL);
    }
};
