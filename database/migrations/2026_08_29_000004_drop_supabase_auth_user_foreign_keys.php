<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy Supabase Auth required user_profiles.id to exist in auth.users.
        // Laravel Sanctum owns user_profiles directly now.
        DB::statement('ALTER TABLE user_profiles DROP CONSTRAINT IF EXISTS user_profiles_id_fkey');
        DB::statement('ALTER TABLE user_profiles DROP CONSTRAINT IF EXISTS user_profiles_deleted_by_fkey');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_profiles
            ADD CONSTRAINT user_profiles_id_fkey
            FOREIGN KEY (id) REFERENCES auth.users(id) ON DELETE CASCADE');

        DB::statement('ALTER TABLE user_profiles
            ADD CONSTRAINT user_profiles_deleted_by_fkey
            FOREIGN KEY (deleted_by) REFERENCES auth.users(id)');
    }
};
