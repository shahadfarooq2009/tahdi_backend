<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_courses') && ! Schema::hasColumn('school_courses', 'import_source')) {
            Schema::table('school_courses', function (Blueprint $table) {
                $table->string('import_source', 255)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('school_courses') && Schema::hasColumn('school_courses', 'import_source')) {
            Schema::table('school_courses', function (Blueprint $table) {
                $table->dropColumn('import_source');
            });
        }
    }
};
