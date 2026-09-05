<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! Schema::hasTable('subjects')) {
    echo "subjects table missing\n";
    exit(1);
}

$statements = [
    'ALTER TABLE subjects DROP CONSTRAINT IF EXISTS subjects_name_key',
    'DROP INDEX IF EXISTS subjects_active_school_name_scope_unique',
    'DROP INDEX IF EXISTS subjects_active_family_name_unique',
    "
        CREATE UNIQUE INDEX subjects_active_school_name_scope_unique
        ON subjects (btrim(name), challenge_type, is_high_school_parent)
        WHERE is_deleted = false AND challenge_type = 'school'
    ",
    "
        CREATE UNIQUE INDEX subjects_active_family_name_unique
        ON subjects (btrim(name))
        WHERE is_deleted = false AND challenge_type = 'family'
    ",
];

foreach ($statements as $sql) {
    DB::statement($sql);
    echo 'applied: '.preg_replace('/\s+/', ' ', trim($sql))."\n";
}

echo "done\n";
