<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_units') && ! Schema::hasColumn('school_units', 'import_source')) {
            Schema::table('school_units', function (Blueprint $table) {
                $table->string('import_source', 255)->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('school_units') && Schema::hasColumn('school_units', 'import_source')) {
            Schema::table('school_units', function (Blueprint $table) {
                $table->dropColumn('import_source');
            });
        }
    }
};
