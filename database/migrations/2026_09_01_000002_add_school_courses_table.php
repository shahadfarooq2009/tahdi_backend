<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subjects') && ! Schema::hasColumn('subjects', 'is_high_school_parent')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->boolean('is_high_school_parent')->default(false)->after('challenge_type');
            });
        }

        if (! Schema::hasTable('school_courses')) {
            Schema::create('school_courses', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('parent_subject_id');
                $table->string('name');
                $table->string('code', 32)->nullable();
                $table->string('grade', 32);
                $table->unsignedInteger('display_order')->default(0);
                $table->timestamps();

                $table->foreign('parent_subject_id')
                    ->references('id')
                    ->on('subjects')
                    ->cascadeOnDelete();
                $table->unique(['parent_subject_id', 'grade', 'name'], 'school_courses_parent_grade_name_unique');
                $table->index(['parent_subject_id', 'grade'], 'school_courses_parent_grade_index');
            });
        }

        if (Schema::hasTable('school_units') && ! Schema::hasColumn('school_units', 'course_id')) {
            Schema::table('school_units', function (Blueprint $table) {
                $table->uuid('course_id')->nullable()->after('subject_id');
                $table->foreign('course_id')
                    ->references('id')
                    ->on('school_courses')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('school_units') && Schema::hasColumn('school_units', 'course_id')) {
            Schema::table('school_units', function (Blueprint $table) {
                $table->dropForeign(['course_id']);
                $table->dropColumn('course_id');
            });
        }

        Schema::dropIfExists('school_courses');

        if (Schema::hasTable('subjects') && Schema::hasColumn('subjects', 'is_high_school_parent')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->dropColumn('is_high_school_parent');
            });
        }
    }
};
