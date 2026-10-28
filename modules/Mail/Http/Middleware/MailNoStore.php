<?php

namespace Modules\Mail\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every mail response: `Cache-Control: private, no-store` (pages, JSON, errors and throttling alike). */
final class MailNoStore
{
    /** Also on the 404 of the {message} binding, which runs before this middleware (MailServiceProvider). */
    public const HEADERS = ['Cache-Control' => 'private, no-store'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->add(self::HEADERS);

        return $response;
    }
}
