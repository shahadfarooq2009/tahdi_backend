<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('textbook_pages')) {
            Schema::table('textbook_pages', function (Blueprint $table): void {
                if (! Schema::hasColumn('textbook_pages', 'printed_page_number')) {
                    $table->unsignedSmallInteger('printed_page_number')->nullable()->after('normalized_text');
                }

                if (! Schema::hasColumn('textbook_pages', 'extraction_source')) {
                    $table->string('extraction_source', 24)->nullable()->after('printed_page_number');
                }

                if (! Schema::hasColumn('textbook_pages', 'extraction_quality')) {
                    $table->json('extraction_quality')->nullable()->after('extraction_source');
                }
            });
        }

        if (Schema::hasTable('textbooks')) {
            Schema::table('textbooks', function (Blueprint $table): void {
                if (! Schema::hasColumn('textbooks', 'extraction_diagnostics')) {
                    $table->json('extraction_diagnostics')->nullable()->after('last_error');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('textbook_pages')) {
            Schema::table('textbook_pages', function (Blueprint $table): void {
                foreach (['printed_page_number', 'extraction_source', 'extraction_quality'] as $column) {
                    if (Schema::hasColumn('textbook_pages', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('textbooks')) {
            Schema::table('textbooks', function (Blueprint $table): void {
                if (Schema::hasColumn('textbooks', 'extraction_diagnostics')) {
                    $table->dropColumn('extraction_diagnostics');
                }
            });
        }
    }
};
