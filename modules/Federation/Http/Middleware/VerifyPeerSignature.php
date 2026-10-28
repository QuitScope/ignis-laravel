<?php

namespace Modules\Federation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Support\PeerSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound peer auth. Runs behind `module:federation` (404 while off). Unknown, inactive, forged and
 * replayed requests all answer `unauthorized` (no oracle); only `clock_skew` is told apart so admins can spot NTP
 * trouble (checked before the link lookup). Failures are throttled per IP and instance header (30/min, then 429) and logged with IP and path only.
 */
final class VerifyPeerSignature
{
    private const MAX_FAILURES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $limiterKey = 'federation-failures:'.$request->ip().'|'.mb_substr((string) $request->header('X-Ignis-Instance'), 0, 64);
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_FAILURES)) {
            return response()->json(['success' => false, 'error' => 'too_many_attempts'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limiterKey));
        }

        $code = 'protocol_v2_required';
        $link = null;
        if ($request->header('X-Ignis-Federation') === '2' && ! PeerSignature::fresh((string) $request->header('X-Ignis-Timestamp'))) {
            $code = 'clock_skew';
        } elseif ($request->header('X-Ignis-Federation') === '2') {
            $link = FederationLink::query()->where('instance_id', (string) $request->header('X-Ignis-Instance'))->where('is_active', true)->first();
            $code = $link === null ? 'unauthorized' : PeerSignature::verify($request, $link);
        }

        if ($code !== null || $link === null) {
            RateLimiter::hit($limiterKey, 60);
            Log::warning('Federation request rejected', ['ip' => $request->ip(), 'path' => $request->path()]);

            return $this->fail($code === 'clock_skew' || $code === 'protocol_v2_required' ? $code : 'unauthorized');
        }

        if ($link->confirmed_at === null) {
            $link->forceFill(['confirmed_at' => now()])->save();
        }
        $request->attributes->set('federation_link', $link);

        return $next($request);
    }

    private function fail(string $code): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $code], 401);
    }
}
