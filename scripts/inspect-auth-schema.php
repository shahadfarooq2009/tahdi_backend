<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fk = DB::select("SELECT conname, pg_get_constraintdef(c.oid) AS def
    FROM pg_constraint c
    JOIN pg_class t ON c.conrelid = t.oid
    WHERE t.relname = 'user_profiles' AND c.contype = 'f'");

echo "user_profiles FK constraints:\n";
foreach ($fk as $row) {
    echo "  {$row->conname}: {$row->def}\n";
}

$usersCols = DB::select("SELECT column_name, data_type, is_nullable
    FROM information_schema.columns
    WHERE table_schema = 'auth' AND table_name = 'users'
    ORDER BY ordinal_position");

echo "\nauth.users columns:\n";
foreach ($usersCols as $col) {
    echo "  {$col->column_name} ({$col->data_type}, nullable={$col->is_nullable})\n";
}

$usersCols = DB::select("SELECT column_name, data_type, is_nullable
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'users'
    ORDER BY ordinal_position");

echo "\npublic.users columns:\n";
foreach ($usersCols as $col) {
    echo "  {$col->column_name} ({$col->data_type}, nullable={$col->is_nullable})\n";
}

$profiles = DB::select("SELECT email,
    CASE WHEN password IS NULL THEN 'no' ELSE 'yes' END AS password_present,
    CASE
      WHEN password LIKE '\$2y\$%' OR password LIKE '\$2a\$%' OR password LIKE '\$argon2%' THEN 'yes'
      ELSE 'no'
    END AS looks_hashed
    FROM user_profiles
    WHERE is_deleted = false
    ORDER BY created_at DESC NULLS LAST
    LIMIT 10");

echo "\nRecent user_profiles (password metadata only):\n";
foreach ($profiles as $p) {
    echo "  email={$p->email} password_present={$p->password_present} looks_hashed={$p->looks_hashed}\n";
}
