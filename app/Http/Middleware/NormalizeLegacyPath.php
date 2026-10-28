<?php

namespace App\Http\Middleware;

use App\Http\LegacyUrlMap;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global, runs before routing. Keeps ef_bridge's `.php` URLs and old bookmarks working:
 *
 *  1. strip a trailing `.php` (not `/index.php`, not a bare `.php` segment), internal rewrite;
 *  2. German segments → English (LegacyUrlMap::translate): 301 for GET/HEAD page requests,
 *     internal rewrite for other methods (a redirect would drop the body), for `/api/…` and for
 *     `einsatz/…` (opened by the FiveM tablet);
 *  3. legacy English pages (LegacyUrlMap::redirects): 301 (or the table's status) for GET/HEAD; legacy mail pages
 *     only when the request carries the legacy marker `?postfach=` or `.php` (LegacyUrlMap::mailRedirect);
 *  4. `/api/v1/X` → `/api/X` and legacy endpoints (LegacyUrlMap::rewrites): internal rewrite for every method.
 *
 * The query string is passed on byte for byte; the path is never decoded. Redirect targets come
 * only from the fixed tables. In the Docker image, docker/nginx/legacy-php.conf routes `*.php`
 * URLs to index.php (REQUEST_URI stays the original), so rule 1 applies there too.
 */
final class NormalizeLegacyPath
{
    /** @var array<string, true>|null First path segments that may need work. */
    private static ?array $candidates = null;

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();
        $isPhp = str_ends_with($path, '.php') && $path !== '/index.php' && ! str_ends_with($path, '/.php');
        $stripped = $isPhp ? substr($path, 0, -4) : $path;
        $first = explode('/', $stripped, 3)[1] ?? '';

        if (! $isPhp && ! isset(self::candidates()[$first])) {
            return $next($request);
        }

        $translated = LegacyUrlMap::translate($stripped);
        $target = $translated ?? $stripped;

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $mail = LegacyUrlMap::mailRedirect($target, self::query($request), $isPhp);
            if ($mail !== null) {
                return new RedirectResponse($mail, 301);
            }
            $redirect = LegacyUrlMap::redirectFor($target);
            $redirectTranslation = $translated !== null && $first !== 'api' && $first !== 'einsatz';
            if ($redirectTranslation || $redirect !== null) {
                [$location, $status] = $redirect ?? [$target, 301];
                $location = LegacyUrlMap::redirectLocation($target, $location, self::query($request));
                // Belt and braces against open redirects: a single leading slash, never `//` or `/\`.
                if (preg_match('#^/(?![/\\\\])#', $location) === 1) {
                    return new RedirectResponse($location.LegacyUrlMap::redirectQuery($target, self::query($request)), $status);
                }
            }
        }

        // The legacy front controller served `/api/v1/X` as `/api/X` (its browser JS still calls the versioned form).
        if (str_starts_with($target, '/api/v1/')) {
            $target = '/api'.substr($target, 7);
        }

        $target = LegacyUrlMap::rewrites()[$target] ?? $target;

        if ($target === $path) {
            return $next($request);
        }

        $rewritten = $request->duplicate();
        $rewritten->server->set('REQUEST_URI', $request->getBaseUrl().$target.self::query($request));

        return $next($rewritten);
    }

    private static function query(Request $request): string
    {
        $uri = $request->getRequestUri();
        $pos = strpos($uri, '?');

        return $pos === false ? '' : substr($uri, $pos);
    }

    /** @return array<string, true> */
    private static function candidates(): array
    {
        if (self::$candidates === null) {
            $keys = [...array_keys(LegacyUrlMap::topSegments()), 'settings', 'api', 'mail'];
            foreach (array_keys(LegacyUrlMap::rewrites()) as $from) {
                $keys[] = explode('/', $from, 3)[1];
            }
            foreach (array_keys(LegacyUrlMap::redirects()) as $from) {
                $keys[] = explode('/', $from, 3)[1];
            }
            foreach (LegacyUrlMap::patternRedirects() as [, , $sample]) {
                $keys[] = explode('/', $sample, 3)[1];
            }
            self::$candidates = array_fill_keys($keys, true);
        }

        return self::$candidates;
    }
}
