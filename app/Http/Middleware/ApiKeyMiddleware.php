<?php

namespace App\Http\Middleware;

use App\Auth\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Machine-to-machine auth for ef_bridge (FiveM server -> ignis). No localhost bypass.
 *
 * Key sources, first string wins: header `X-API-Key`, query `api_key`, JSON body `intraRP_API_Key`,
 * JSON body `api_key` (the body forms are what ef_bridge historically sends). Prefer the header:
 * the query form ends up in web-server/proxy access logs. Form bodies and cookies are ignored.
 *
 * A valid key always passes, never touching the limiter, so nobody sharing the FiveM server's IP
 * can lock it out. Only failures are throttled per IP: past MAX_FAILURES per minute they get 429
 * (unlogged, to cap log noise). The key and payload are never logged: a 403 logs only IP and path.
 */
final class ApiKeyMiddleware
{
    private const MAX_FAILURES = 30;

    private const DECAY_SECONDS = 60;

    public function __construct(private readonly ApiKey $apiKey) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->apiKey->isConfigured()) {
            return $this->fail('API-Key nicht konfiguriert', 503);
        }

        if ($this->apiKey->matches($this->providedKey($request))) {
            $request->attributes->set('api_auth', 'key');

            return $next($request);
        }

        $limiterKey = 'api-key-failures:'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_FAILURES)) {
            return $this->fail('Zu viele Versuche', 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limiterKey));
        }

        RateLimiter::hit($limiterKey, self::DECAY_SECONDS);
        Log::warning('API key rejected', ['ip' => $request->ip(), 'path' => $request->path()]);

        return $this->fail('Zugriff verweigert', 403);
    }

    private function providedKey(Request $request): ?string
    {
        $json = $request->json()->all();
        foreach ([$request->header('X-API-Key'), $request->query('api_key'), $json['intraRP_API_Key'] ?? null, $json['api_key'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
