<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global, outermost (also wraps NormalizeLegacyPath's redirects). `X-Frame-Options: SAMEORIGIN`
 * on every response that has none yet, except for FiveM's CEF (FiveMWebview::isFiveM). Lives in
 * the app, not nginx (the Dockerfile strips it from the image's security.conf), so that
 * exception is possible; FiveMWebview additionally drops CSP and a route's own header.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! FiveMWebview::isFiveM($request) && ! $response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
