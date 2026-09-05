<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_user_question_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('chapter_id');
            $table->uuid('question_id');
            $table->uuid('game_session_id')->nullable();
            $table->unsignedSmallInteger('points_value');
            $table->timestamp('assigned_at');
            $table->timestamp('completed_at')->nullable();

            $table->unique(['user_id', 'chapter_id', 'question_id'], 'unit_user_question_assignments_unique');
            $table->index(['user_id', 'chapter_id']);
            $table->index(['game_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_user_question_assignments');
    }
};
