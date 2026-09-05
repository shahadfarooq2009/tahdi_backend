<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('questions') && ! Schema::hasColumn('questions', 'import_source')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->string('import_source', 255)->nullable()->after('question_source');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('questions') && Schema::hasColumn('questions', 'import_source')) {
            Schema::table('questions', function (Blueprint $table) {
                $table->dropColumn('import_source');
            });
        }
    }
};
