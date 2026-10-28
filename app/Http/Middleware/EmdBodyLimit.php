<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Size and depth guard for the EMD sync. Global, right after NormalizeLegacyPath (so `.php` / emd-sync
 * are already rewritten) and before TrimStrings / ConvertEmptyStringsToNull and api.key, which all decode the JSON body
 * (depth 512): the cap must come first. Only api/emd/sync; above MAX_BYTES 413, nested deeper than MAX_DEPTH 400.
 */
final class EmdBodyLimit
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const MAX_DEPTH = 32;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/emd/sync')) {
            return $next($request);
        }
        if ((int) $request->header('Content-Length', '0') > self::MAX_BYTES || strlen($request->getContent()) > self::MAX_BYTES) {
            return response()->json(['success' => false, 'error' => 'Anfrage zu groß'], 413);
        }
        json_decode($request->getContent(), true, self::MAX_DEPTH);
        if (json_last_error() === JSON_ERROR_DEPTH) {
            return response()->json(['success' => false, 'error' => 'Ungültiges JSON'], 400);
        }

        return $next($request);
    }
}
