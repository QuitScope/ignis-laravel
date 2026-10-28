<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `fivem` for pages shown in FiveM's CEF webview (FireTab routes, /auth/tablet), JSON included.
 * FiveM is recognised by the CitizenFX user agent, `Sec-Fetch-Dest: iframe` or `X-Iframe-Request` (legacy
 * SessionManager methods 1 and 3), and then for the rest of the session, because the tablet's XHR/fetch calls
 * send no iframe header. For those requests: no CSP / X-Frame-Options (SecurityHeaders skips them too), and over
 * HTTPS the session and XSRF-TOKEN cookies get `SameSite=None; Secure` so they survive the cross-site NUI iframe.
 * Plain HTTP stays Lax (Secure is impossible there). Normal requests are untouched. docs/dev/cef.md
 */
final class FiveMWebview
{
    private const KEY = 'fivem';

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->hasSession() ? $request->session() : null;
        $detected = self::isFiveM($request)
            || $request->header('Sec-Fetch-Dest') === 'iframe'
            || (string) $request->header('X-Iframe-Request') !== ''
            || $session?->get(self::KEY) === true;

        if (! $detected) {
            return $next($request);
        }

        $request->attributes->set(self::KEY, true);
        if ($request->isSecure()) {
            // StartSession and VerifyCsrfToken read this config when they write their cookies, after this middleware
            // returns. ponytail: per-process config change, fine for PHP-FPM; reset it if the app ever runs under Octane.
            config(['session.same_site' => 'none', 'session.secure' => true]);
        }

        $response = $next($request);
        $session?->put(self::KEY, true);
        $response->headers->remove('Content-Security-Policy');
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    public static function isFiveM(Request $request): bool
    {
        return $request->attributes->get(self::KEY) === true || stripos((string) $request->userAgent(), 'CitizenFX') !== false;
    }
}
