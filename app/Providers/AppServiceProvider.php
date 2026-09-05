<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local')) {
            // Keep admin/game/ai rate limiting off remote Supabase even if CACHE_STORE is mis-set.
            $this->app->singleton(RateLimiter::class, function ($app) {
                return new RateLimiter(Cache::store('array'));
            });
        }
    }

    public function boot(): void
    {
        RateLimiterFacade::for('admin', function (Request $request) {
            $limit = $this->app->environment('local')
                ? Limit::perMinute(600)
                : Limit::perMinutes(15, 100);

            return $limit->by(
                $request->attributes->get('auth_user')?->id ?? $request->ip()
            );
        });

        RateLimiterFacade::for('game', function (Request $request) {
            return Limit::perMinutes(15, 300)->by(
                $request->attributes->get('auth_user')?->id ?? $request->ip()
            );
        });

        RateLimiterFacade::for('ai', function (Request $request) {
            $windowMinutes = (int) ceil(((int) env('RATE_LIMIT_AI_WINDOW_MS', 900000)) / 60000);

            return Limit::perMinutes($windowMinutes, (int) env('RATE_LIMIT_AI_MAX', 30))->by(
                $request->attributes->get('auth_user')?->id ?? $request->ip()
            );
        });
    }
}
