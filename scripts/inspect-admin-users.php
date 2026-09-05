<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'password_reset_tokens table: '.(Illuminate\Support\Facades\Schema::hasTable('password_reset_tokens') ? 'yes' : 'no')."\n";

$admins = DB::table('user_profiles')
    ->where('role', 'admin')
    ->where('is_deleted', false)
    ->select('id', 'email', 'role', DB::raw('CASE WHEN password IS NULL OR password = \'\' THEN false ELSE true END AS password_present'))
    ->limit(10)
    ->get();

echo "admin accounts:\n";
foreach ($admins as $admin) {
    echo "  {$admin->email} role={$admin->role} password_present=".($admin->password_present ? 'yes' : 'no')."\n";
}
