<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_profiles')) {
            return;
        }

        Schema::table('user_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('user_profiles', 'game_history_cleared_at')) {
                $table->timestampTz('game_history_cleared_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_profiles')) {
            return;
        }

        Schema::table('user_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('user_profiles', 'game_history_cleared_at')) {
                $table->dropColumn('game_history_cleared_at');
            }
        });
    }
};
