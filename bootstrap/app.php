<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\ApiAuthenticate;
use App\Http\Middleware\LogAdminRequestTiming;
use App\Http\Middleware\RequireAnyPermission;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.timing' => LogAdminRequestTiming::class,
            'api.auth' => ApiAuthenticate::class,
            'permission' => RequirePermission::class,
            'permission.any' => RequireAnyPermission::class,
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHORIZED',
                        'message' => 'Authentication required',
                    ],
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (ApiException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json($exception->toArray(), $exception->getStatusCode());
            }

            return null;
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many requests. Please wait and try again.',
                    ],
                ], 429);
            }

            return null;
        });

        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $postMax = ini_get('post_max_size') ?: 'unknown';

                logger()->warning('API request payload too large', [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'content_length' => $request->server('CONTENT_LENGTH'),
                    'post_max_size' => $postMax,
                ]);

                return response()->json([
                    'error' => [
                        'code' => 'PAYLOAD_TOO_LARGE',
                        'message' => 'حجم الملف كبير جداً للخادم (post_max_size = '.$postMax.'). أعد تشغيل Laravel backend وApache إن لزم.',
                        'details' => [
                            'post_max_size' => $postMax,
                            'upload_max_filesize' => ini_get('upload_max_filesize') ?: 'unknown',
                        ],
                    ],
                ], 413);
            }

            return null;
        });

        $exceptions->render(function (QueryException $exception, Request $request) {
            if ($exception instanceof ApiException || $exception instanceof PostTooLargeException) {
                return null;
            }

            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            logger()->error('API database error', [
                'method' => $request->method(),
                'path' => $request->path(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            if (str_contains($exception->getMessage(), 'textbooks_processing_status_check')) {
                return response()->json([
                    'error' => [
                        'code' => 'SCHEMA_OUTDATED',
                        'message' => 'تعذر تحديث حالة الكتاب. شغّل ترحيل قاعدة البيانات: php artisan migrate',
                    ],
                ], 500);
            }

            if (str_contains($request->path(), '/upload')) {
                return response()->json([
                    'error' => [
                        'code' => 'DATABASE_ERROR',
                        'message' => 'تم رفع الملف ولكن تعذر تسجيل بيانات الكتاب.',
                    ],
                ], 500);
            }

            if (str_contains($request->path(), '/process')) {
                return response()->json([
                    'error' => [
                        'code' => 'DATABASE_ERROR',
                        'message' => 'تعذر بدء معالجة الكتاب. يرجى المحاولة مرة أخرى.',
                    ],
                ], 500);
            }

            if (str_contains($exception->getMessage(), 'could not translate host name')
                || str_contains($exception->getMessage(), 'Connection refused')
                || str_contains($exception->getMessage(), 'could not connect to server')) {
                return response()->json([
                    'error' => [
                        'code' => 'DATABASE_UNAVAILABLE',
                        'message' => 'تعذر الاتصال بقاعدة البيانات. تحقق من الاتصال بالإنترنت ثم أعد المحاولة.',
                    ],
                ], 503);
            }

            if (str_contains($exception->getMessage(), 'Unique violation')
                || str_contains($exception->getMessage(), 'unique constraint')
                || str_contains($exception->getMessage(), '23505')) {
                return response()->json([
                    'error' => [
                        'code' => 'CONFLICT',
                        'message' => 'يوجد مادة أخرى بنفس الاسم في نفس المرحلة. يمكنك استخدام نفس الاسم في مرحلة مختلفة فقط.',
                    ],
                ], 409);
            }

            return null;
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if ($exception instanceof ApiException || $exception instanceof PostTooLargeException) {
                return null;
            }

            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            logger()->error('API request failed', [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => 500,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            if (config('app.debug')) {
                report($exception);
            }

            $message = match (true) {
                str_contains($request->path(), '/upload') => 'حدث خطأ أثناء رفع الكتاب. يرجى المحاولة مرة أخرى.',
                str_contains($request->path(), '/process') => 'تعذر بدء معالجة الكتاب. يرجى المحاولة مرة أخرى.',
                default => 'حدث خطأ غير متوقع. يرجى المحاولة مرة أخرى.',
            };

            return response()->json([
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => $message,
                ],
            ], 500);
        });
    })->create();
