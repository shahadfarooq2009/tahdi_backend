<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\SupabaseTestTokens;

$iterations = max(1, (int) ($argv[1] ?? 10));
$baseUrl = rtrim((string) env('BENCHMARK_BASE_URL', 'http://127.0.0.1:8000'), '/');
$logFile = storage_path('logs/laravel.log');

$endpoints = [
    '/api/admin/categories',
    '/api/admin/subjects',
    '/api/admin/questions',
    '/api/admin/textbooks',
];

$secret = config('supabase.jwt_secret');
if (! filled($secret)) {
    fwrite(STDERR, "SUPABASE_JWT_SECRET is not configured.\n");
    exit(1);
}

$issuer = config('supabase.jwt_issuer');
if (! filled($issuer) && filled(config('supabase.url'))) {
    $issuer = rtrim((string) config('supabase.url'), '/').'/auth/v1';
}

$admin = DB::table('user_profiles')
    ->where('role', 'admin')
    ->where('is_active', true)
    ->orderBy('email')
    ->first(['id', 'email']);

if ($admin === null) {
    fwrite(STDERR, "No active admin user found.\n");
    exit(1);
}

$token = SupabaseTestTokens::make(
    userId: (string) $admin->id,
    email: (string) $admin->email,
    secret: (string) $secret,
    issuer: (string) $issuer,
);

function requestEndpoint(string $baseUrl, string $endpoint, string $token): array
{
    $startedAt = microtime(true);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
            'timeout' => 25,
        ],
    ]);

    $body = @file_get_contents($baseUrl.$endpoint, false, $context);
    $elapsed = (int) round((microtime(true) - $startedAt) * 1000);

    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches) === 1) {
        $status = (int) $matches[1];
    }

    return ['status' => $status, 'ms' => $elapsed, 'ok' => $body !== false];
}

function readLatestTimingSegment(string $logFile, string $path): ?array
{
    if (! is_readable($logFile)) {
        return null;
    }

    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (! is_array($lines)) {
        return null;
    }

    for ($index = count($lines) - 1; $index >= 0; $index--) {
        $line = $lines[$index];
        if (! str_contains($line, 'admin.request.timing') || ! str_contains($line, $path)) {
            continue;
        }

        if (preg_match('/\{.*\}$/', $line, $matches) !== 1) {
            continue;
        }

        $payload = json_decode($matches[0], true);
        if (is_array($payload)) {
            return $payload;
        }
    }

    return null;
}

echo 'auth_mode=local_hs256'.PHP_EOL;
echo 'jwt_secret_configured=yes'.PHP_EOL;
echo 'auth_cache_ttl='.config('supabase.auth_cache_ttl').'s'.PHP_EOL;
echo 'profile_cache_ttl='.config('supabase.profile_cache_ttl').'s'.PHP_EOL.PHP_EOL;

// Cold pass: clear caches so first request pays full auth/profile cost.
Cache::flush();

echo "=== COLD (cache flushed, 1 request each) ===\n";
$cold = [];
foreach ($endpoints as $endpoint) {
    $result = requestEndpoint($baseUrl, $endpoint, $token);
    $cold[$endpoint] = $result;
    $timing = readLatestTimingSegment($logFile, ltrim($endpoint, '/'));
  echo sprintf(
      "%s: HTTP %d in %dms%s\n",
      $endpoint,
      $result['status'],
      $result['ms'],
      $timing ? ' | log_total_ms='.($timing['total_ms'] ?? 'n/a').' segments='.json_encode($timing['segments_ms'] ?? []) : ''
  );
}
echo PHP_EOL;

// Warm pass: repeat without clearing caches.
echo "=== WARM ($iterations requests each) ===\n";
$warm = [];
foreach ($endpoints as $endpoint) {
    $times = [];
    for ($i = 1; $i <= $iterations; $i++) {
        $result = requestEndpoint($baseUrl, $endpoint, $token);
        $times[] = $result['ms'];
        if ($i <= 3 || $i === $iterations) {
            echo sprintf("%s req %d: HTTP %d in %dms\n", $endpoint, $i, $result['status'], $result['ms']);
        } elseif ($i === 4) {
            echo "... {$endpoint} requests 4-".($iterations - 1)." omitted\n";
        }
    }

    sort($times);
    $avg = (int) round(array_sum($times) / count($times));
    $p50 = $times[(int) floor((count($times) - 1) * 0.5)];
    $p90 = $times[(int) floor((count($times) - 1) * 0.9)];
    $max = max($times);

    $warm[$endpoint] = ['avg' => $avg, 'p50' => $p50, 'p90' => $p90, 'max' => $max, 'times' => $times];

    echo sprintf(
        "%s warm summary: avg=%dms p50=%dms p90=%dms max=%dms\n\n",
        $endpoint,
        $avg,
        $p50,
        $p90,
        $max
    );
}

echo "=== SUMMARY ===\n";
foreach ($endpoints as $endpoint) {
    echo sprintf(
        "%s | cold=%dms | warm_avg=%dms | warm_p90=%dms | warm_max=%dms\n",
        $endpoint,
        $cold[$endpoint]['ms'],
        $warm[$endpoint]['avg'],
        $warm[$endpoint]['p90'],
        $warm[$endpoint]['max']
    );
}

$categoriesMax = max($cold['/api/admin/categories']['ms'], $warm['/api/admin/categories']['max']);
echo PHP_EOL.'categories_timeout_risk='.($categoriesMax >= 20000 ? 'yes' : 'no').' (max '.$categoriesMax.'ms vs 20000ms frontend timeout)'.PHP_EOL;
