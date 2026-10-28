<?php

namespace App\Http\Middleware;

use App\Install\InstallState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** First in the web group: sends everything to /install until installed, then closes /install. */
class EnsureInstalled
{
    private const EXEMPT = ['healthz', 'up', 'api/*', 'build/*', 'fonts/*', 'favicon.ico', 'robots.txt'];

    public function __construct(private readonly InstallState $state) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...self::EXEMPT)) {
            return $next($request);
        }

        $installing = $request->is('install', 'install/*');
        $installed = $this->state->isInstalled();

        if ($installed && $installing) {
            abort(404);
        }

        if (! $installed && config('session.driver') === 'database') {
            config(['session.driver' => 'file']);
        }

        if (! $installed && ! $installing) {
            return redirect('/install');
        }

        return $next($request);
    }
}
