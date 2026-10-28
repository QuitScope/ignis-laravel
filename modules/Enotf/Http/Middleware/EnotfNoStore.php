<?php

namespace Modules\Enotf\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Protocols hold personal data: `Cache-Control: private, no-store` on every eNOTF response. */
final class EnotfNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->add(['Cache-Control' => 'private, no-store']);

        return $response;
    }
}
