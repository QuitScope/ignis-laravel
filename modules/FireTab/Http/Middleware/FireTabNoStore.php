<?php

namespace Modules\FireTab\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Protocols hold personal data: `Cache-Control: private, no-store` on every FireTab response. */
final class FireTabNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->add(['Cache-Control' => 'private, no-store']);

        return $response;
    }
}
