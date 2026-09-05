<?php

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenException;
use App\Services\Auth\AuthenticatedUser;
use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->attributes->get('auth_user');

        if (! $user instanceof AuthenticatedUser || ! Roles::isValidRole($user->role)) {
            throw new ForbiddenException();
        }

        if (! in_array($user->role, $roles, true)) {
            throw new ForbiddenException();
        }

        return $next($request);
    }
}
