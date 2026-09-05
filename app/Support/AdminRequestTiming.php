<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminRequestTiming
{
  /**
   * @var array<string, float>
   */
  private static array $timestamps = [];

  /**
   * @var array<string, float>
   */
  private static array $segments = [];

  public static function enabled(): bool
  {
    return config('app.debug') && config('app.env') === 'local';
  }

  public static function mark(string $name): void
  {
    if (! self::enabled()) {
      return;
    }

    self::$timestamps[$name] = microtime(true);
  }

  public static function segment(string $name, float $milliseconds): void
  {
    if (! self::enabled()) {
      return;
    }

    self::$segments[$name] = round($milliseconds, 1);
  }

  public static function segmentSince(string $name, string $startMark): void
  {
    if (! self::enabled() || ! isset(self::$timestamps[$startMark])) {
      return;
    }

    self::segment($name, (microtime(true) - self::$timestamps[$startMark]) * 1000);
  }

  public static function flush(Request $request): void
  {
    if (! self::enabled() || self::$segments === [] && self::$timestamps === []) {
      return;
    }

    $totalMs = isset(self::$timestamps['request_start'])
      ? round((microtime(true) - self::$timestamps['request_start']) * 1000, 1)
      : null;

    Log::debug('admin.request.timing', [
      'request_id' => $request->header('X-Request-ID'),
      'method' => $request->method(),
      'path' => $request->path(),
      'authorization_present' => $request->bearerToken() !== null,
      'client_token_retrieval_ms' => $request->header('X-Client-Token-Retrieval-Ms'),
      'total_ms' => $totalMs,
      'segments_ms' => self::$segments,
      'cache_driver' => config('cache.default'),
    ]);

    self::$timestamps = [];
    self::$segments = [];
  }
}
