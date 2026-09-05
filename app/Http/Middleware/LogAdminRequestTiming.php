<?php

namespace App\Http\Middleware;

use App\Support\AdminRequestTiming;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogAdminRequestTiming
{
  public function handle(Request $request, Closure $next): Response
  {
    if (AdminRequestTiming::enabled()) {
      AdminRequestTiming::mark('request_start');
    }

    return $next($request);
  }

  public function terminate(Request $request, Response $response): void
  {
    AdminRequestTiming::flush($request);
  }
}
