<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Admin\CategoryService;
use App\Services\Auth\SupabaseAuthService;
use App\Services\Auth\UserProfileService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

$token = getenv('BENCHMARK_ACCESS_TOKEN') ?: '';

echo 'cache.default='.config('cache.default').PHP_EOL;
echo 'rate_limiter_store=array (local singleton)'.PHP_EOL.PHP_EOL;

$segments = [];

$start = microtime(true);
RateLimiter::attempt('benchmark-admin', 100, fn () => true, 900);
$segments['rate_limiter_ms'] = round((microtime(true) - $start) * 1000);

$start = microtime(true);
DB::select('select 1');
$segments['db_ping_ms'] = round((microtime(true) - $start) * 1000);

$start = microtime(true);
app(CategoryService::class)->list(['is_deleted' => false]);
$segments['category_service_ms'] = round((microtime(true) - $start) * 1000);

if ($token !== '') {
    $auth = app(SupabaseAuthService::class);
    $profiles = app(UserProfileService::class);

    $start = microtime(true);
    $authUser = $auth->verifyAccessToken($token);
    $segments['supabase_auth_http_ms'] = round((microtime(true) - $start) * 1000);

    $start = microtime(true);
    $profiles->getProfileForUser($token, (string) $authUser['id']);
    $segments['profile_lookup_ms'] = round((microtime(true) - $start) * 1000);

    $start = microtime(true);
    $profiles->resolveAuthenticatedUser('Bearer '.$token);
    $segments['full_auth_stack_ms'] = round((microtime(true) - $start) * 1000);
} else {
  echo "Set BENCHMARK_ACCESS_TOKEN to measure authenticated stack.\n";
}

foreach ($segments as $label => $ms) {
    echo sprintf("[%s] %dms\n", $label, $ms);
}

$estimatedAuthenticated = ($segments['rate_limiter_ms'] ?? 0)
    + ($segments['full_auth_stack_ms'] ?? ($segments['supabase_auth_http_ms'] ?? 800) + ($segments['profile_lookup_ms'] ?? 1500))
    + ($segments['category_service_ms'] ?? 0);

echo PHP_EOL.'estimated_authenticated_total_ms='.$estimatedAuthenticated.PHP_EOL;
