<?php

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenException;
use App\Services\Auth\AuthenticatedUser;
use App\Support\AdminRequestTiming;
use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        AdminRequestTiming::segmentSince('throttle_ms', 'after_api_auth');

        $permissionStartedAt = microtime(true);

        $user = $request->attributes->get('auth_user');

        if (! $user instanceof AuthenticatedUser) {
            throw new ForbiddenException();
        }

        $allowed = collect($permissions)->contains(
            fn (string $permission) => Roles::roleHasPermission($user->role, $permission)
        );

        if (! $allowed) {
            throw new ForbiddenException();
        }

        AdminRequestTiming::segment('permission_ms', (microtime(true) - $permissionStartedAt) * 1000);

        return $next($request);
    }
}
