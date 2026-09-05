<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('subject_id');
            $table->string('educational_stage', 32)->nullable();
            $table->string('grade', 32)->nullable();
            $table->unsignedInteger('unit_number');
            $table->string('title');
            $table->unsignedInteger('display_order')->default(0);
            $table->uuid('chapter_id')->nullable();
            $table->timestamps();

            $table->unique(['subject_id', 'educational_stage', 'grade', 'unit_number'], 'school_units_scope_number_unique');
            $table->index(['subject_id', 'educational_stage', 'grade'], 'school_units_scope_index');
        });

        Schema::create('school_games', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('unit_id');
            $table->unsignedInteger('game_number');
            $table->string('title');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['unit_id', 'game_number'], 'school_games_unit_number_unique');
            $table->foreign('unit_id')->references('id')->on('school_units')->cascadeOnDelete();
        });

        Schema::create('user_game_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('game_id');
            $table->uuid('game_session_id')->nullable();
            $table->unsignedInteger('score')->nullable();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->unique(['user_id', 'game_id'], 'user_game_progress_user_game_unique');
            $table->foreign('game_id')->references('id')->on('school_games')->cascadeOnDelete();
            $table->index('user_id');
        });

        if (Schema::hasTable('questions') && ! Schema::hasColumn('questions', 'game_id')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->uuid('game_id')->nullable()->after('chapter_id');
                $table->foreign('game_id')->references('id')->on('school_games')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('questions') && Schema::hasColumn('questions', 'game_id')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->dropForeign(['game_id']);
                $table->dropColumn('game_id');
            });
        }

        Schema::dropIfExists('user_game_progress');
        Schema::dropIfExists('school_games');
        Schema::dropIfExists('school_units');
    }
};
