<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_play_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('title', 500);
            $table->string('source_file_name', 255)->nullable();
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('question_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('user_play_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('play_set_id');
            $table->text('question_text');
            $table->text('answer_text');
            $table->unsignedSmallInteger('points_value')->default(200);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_approved')->default(true);
            $table->timestamps();

            $table->foreign('play_set_id')->references('id')->on('user_play_sets')->cascadeOnDelete();
            $table->index('play_set_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_play_questions');
        Schema::dropIfExists('user_play_sets');
    }
};
