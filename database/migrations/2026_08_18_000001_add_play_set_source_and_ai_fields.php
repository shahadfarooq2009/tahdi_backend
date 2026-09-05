<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_play_sets', function (Blueprint $table) {
            $table->longText('source_content')->nullable()->after('source_file_name');
        });

        Schema::table('user_play_questions', function (Blueprint $table) {
            $table->boolean('ai_generated')->default(true)->after('is_approved');
        });
    }

    public function down(): void
    {
        Schema::table('user_play_sets', function (Blueprint $table) {
            $table->dropColumn('source_content');
        });

        Schema::table('user_play_questions', function (Blueprint $table) {
            $table->dropColumn('ai_generated');
        });
    }
};
