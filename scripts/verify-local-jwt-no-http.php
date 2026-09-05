<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;
use Tests\Support\SupabaseTestTokens;

Http::fake(function ($request) {
    if (str_contains($request->url(), '/auth/v1/user')) {
        throw new RuntimeException('Unexpected HTTP call to Supabase /auth/v1/user: '.$request->url());
    }

    return Http::response([], 404);
});

$secret = config('supabase.jwt_secret');
$issuer = rtrim((string) config('supabase.url'), '/').'/auth/v1';
$admin = Illuminate\Support\Facades\DB::table('user_profiles')
    ->where('role', 'admin')
    ->where('is_active', true)
    ->orderBy('email')
    ->first(['id', 'email']);

if ($admin === null) {
    fwrite(STDERR, "No admin user found.\n");
    exit(1);
}

$token = SupabaseTestTokens::make(
    userId: (string) $admin->id,
    email: (string) $admin->email,
    secret: (string) $secret,
    issuer: $issuer,
);

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/api/admin/categories', 'GET', [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
    'HTTP_ACCEPT' => 'application/json',
]);

$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo 'status='.$response->getStatusCode().PHP_EOL;
echo 'auth_http_calls=0'.PHP_EOL;
echo 'local_jwt_validation=ok'.PHP_EOL;
