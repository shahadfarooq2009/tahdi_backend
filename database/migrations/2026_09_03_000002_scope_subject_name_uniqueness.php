<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subjects')) {
            return;
        }

        DB::statement('ALTER TABLE subjects DROP CONSTRAINT IF EXISTS subjects_name_key');

        DB::statement('DROP INDEX IF EXISTS subjects_active_school_name_scope_unique');
        DB::statement('DROP INDEX IF EXISTS subjects_active_family_name_unique');

        DB::statement("
            CREATE UNIQUE INDEX subjects_active_school_name_scope_unique
            ON subjects (btrim(name), challenge_type, is_high_school_parent)
            WHERE is_deleted = false AND challenge_type = 'school'
        ");

        DB::statement("
            CREATE UNIQUE INDEX subjects_active_family_name_unique
            ON subjects (btrim(name))
            WHERE is_deleted = false AND challenge_type = 'family'
        ");
    }

    public function down(): void
    {
        if (! Schema::hasTable('subjects')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS subjects_active_school_name_scope_unique');
        DB::statement('DROP INDEX IF EXISTS subjects_active_family_name_unique');

        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS subjects_name_key
            ON subjects (name)
        ');
    }
};
