<?php

namespace App\Http\Middleware;

use App\Vehicles\CrewSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `crew:<context>` (`crew:firetab`, `crew:enotf`; `firetab.crew` = `crew:firetab`). Checks the crew session on
 * every request: vehicle still of one of the context's services and operable, personnel record still linked; else ends it and sends the
 * tablet to the vehicle login. Runs after `auth`.
 */
final class EnsureCrewSession
{
    public function handle(Request $request, Closure $next, string $context = 'firetab'): Response
    {
        $crew = CrewSession::for($context);
        if (! $crew->valid($request->user())) {
            $crew->logout();

            return redirect($crew->loginPath());
        }

        return $next($request);
    }
}
