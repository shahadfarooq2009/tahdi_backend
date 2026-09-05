<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$statements = [
    'ALTER TABLE public.questions ADD COLUMN IF NOT EXISTS import_source VARCHAR(255)',
    'ALTER TABLE public.school_units ADD COLUMN IF NOT EXISTS import_source VARCHAR(255)',
    'ALTER TABLE public.school_courses ADD COLUMN IF NOT EXISTS import_source VARCHAR(255)',
];

foreach ($statements as $sql) {
    DB::statement($sql);
    echo "applied: {$sql}\n";
}

echo 'questions_has_import_source='.(Schema::hasColumn('questions', 'import_source') ? 'yes' : 'no').PHP_EOL;
echo 'school_units_has_import_source='.(Schema::hasColumn('school_units', 'import_source') ? 'yes' : 'no').PHP_EOL;
echo 'school_courses_has_import_source='.(Schema::hasColumn('school_courses', 'import_source') ? 'yes' : 'no').PHP_EOL;
