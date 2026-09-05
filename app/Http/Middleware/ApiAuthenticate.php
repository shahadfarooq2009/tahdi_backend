<?php

namespace App\Http\Middleware;

use App\Exceptions\UnauthorizedException;
use App\Models\User;
use App\Services\Auth\AuthenticatedUser;
use App\Support\AdminRequestTiming;
use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiAuthenticate
{
  public function handle(Request $request, Closure $next): Response
  {
    $authStartedAt = microtime(true);

    /** @var User|null $user */
    $user = $request->user();

    if (! $user instanceof User) {
      throw new UnauthorizedException();
    }

    if ($user->is_active === false || $user->is_deleted === true) {
      throw new UnauthorizedException('Account is inactive');
    }

    $role = Roles::isValidRole($user->role) ? $user->role : 'user';
    $profile = $user->toProfileArray();
    $profile['role'] = $role;

    $authUser = new AuthenticatedUser(
      id: $user->id,
      email: $user->email,
      role: $role,
      profile: $profile,
      accessToken: (string) $request->bearerToken(),
    );

    AdminRequestTiming::segment('api_auth_ms', (microtime(true) - $authStartedAt) * 1000);
    AdminRequestTiming::mark('after_api_auth');

    $request->attributes->set('auth_user', $authUser);
    app()->instance(AuthenticatedUser::class, $authUser);

    return $next($request);
  }
}
