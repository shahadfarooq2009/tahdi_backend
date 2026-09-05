<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbooks', function (Blueprint $table) {
            $table->string('processing_current_stage', 32)->nullable()->after('processing_status');
            $table->json('processing_stage_meta')->nullable()->after('processing_current_stage');
        });
    }

    public function down(): void
    {
        Schema::table('textbooks', function (Blueprint $table) {
            $table->dropColumn(['processing_current_stage', 'processing_stage_meta']);
        });
    }
};
