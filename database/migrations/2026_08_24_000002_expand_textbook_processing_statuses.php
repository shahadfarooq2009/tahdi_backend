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

        // Drop the legacy CHECK constraint before remapping statuses — the old
        // constraint does not allow values like awaiting_unit_review/extracting.
        DB::statement('ALTER TABLE textbooks DROP CONSTRAINT IF EXISTS textbooks_processing_status_check');

        DB::table('textbooks')
            ->where('processing_status', 'processing')
            ->update(['processing_status' => 'extracting']);

        DB::table('textbooks')
            ->where('processing_status', 'review_required')
            ->update(['processing_status' => 'awaiting_unit_review']);

        DB::table('textbooks')
            ->where('processing_status', 'queued')
            ->update(['processing_status' => 'uploaded']);

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

    public function down(): void
    {
        if (! Schema::hasTable('textbooks')) {
            return;
        }

        DB::statement('ALTER TABLE textbooks DROP CONSTRAINT IF EXISTS textbooks_processing_status_check');

        DB::table('textbooks')
            ->whereIn('processing_status', ['extracting', 'analyzing_contents', 'units_detected'])
            ->update(['processing_status' => 'processing']);

        DB::table('textbooks')
            ->where('processing_status', 'awaiting_unit_review')
            ->update(['processing_status' => 'review_required']);

        DB::table('textbooks')
            ->whereIn('processing_status', ['units_approved', 'generating_questions', 'awaiting_question_review'])
            ->update(['processing_status' => 'ready']);

        DB::statement(<<<'SQL'
            ALTER TABLE textbooks ADD CONSTRAINT textbooks_processing_status_check
            CHECK (processing_status IN (
                'uploaded',
                'processing',
                'review_required',
                'ready',
                'failed'
            ))
        SQL);
    }
};
