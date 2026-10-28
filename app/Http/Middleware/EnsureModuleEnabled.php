<?php

namespace App\Http\Middleware;

use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `module:<name>`: a switched-off (or unknown) module answers like a missing route, page and JSON alike, so nobody
 * learns that it exists. ModuleProvider puts it in front of the module's route groups.
 *
 * The router answers a wrong method (405) and OPTIONS (200 with Allow) before any route middleware. For those, this
 * class also runs globally without a module (OPTIONS) and bootstrap/app.php maps the 405 (hides()): a path whose every
 * route sits behind a switched-off module gets the same 404.
 */
class EnsureModuleEnabled
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next, ?string $module = null): Response
    {
        if ($module === null ? $request->isMethod('OPTIONS') && self::hides($request) : ! $this->modules->isEnabled($module)) {
            throw self::notFound($request);
        }

        return $next($request);
    }

    public static function notFound(Request $request): NotFoundHttpException
    {
        return new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
    }

    public static function hides(Request $request): bool
    {
        $modules = app(ModuleRegistry::class);
        $matched = false;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! $route->matches($request, false)) {
                continue;
            }
            $gate = collect($route->middleware())->first(fn (string $m) => str_starts_with($m, 'module:'));
            if ($gate === null || $modules->isEnabled(substr($gate, 7))) {
                return false;
            }
            $matched = true;
        }

        return $matched;
    }
}
