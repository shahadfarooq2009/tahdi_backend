<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('textbook_upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('content_type', 100);
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->json('received_chunks')->default('[]');
            $table->string('file_hash', 64)->nullable();
            $table->string('status', 32)->default('uploading');
            $table->string('title');
            $table->string('academic_stage')->nullable();
            $table->string('grade')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('academic_year')->nullable();
            $table->string('semester')->nullable();
            $table->string('language', 10)->default('ar');
            $table->uuid('textbook_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('textbook_upload_sessions');
    }
};
