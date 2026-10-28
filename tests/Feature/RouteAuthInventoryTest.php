<?php

use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Middleware\EnsureCrewSession;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Modules\Enotf\Http\Middleware\EnsureHospitalSession;
use Modules\Federation\Http\Middleware\VerifyPeerSignature;

const AUTH_MIDDLEWARE = [Authenticate::class, ApiKeyMiddleware::class, EnsureCrewSession::class, VerifyPeerSignature::class, EnsureHospitalSession::class];

const PUBLIC_ROUTES = [
    'GET|HEAD up' => 'framework health route',
    'GET|HEAD healthz' => 'Docker/uptime probe',
    'GET|HEAD api/health' => 'uptime probe',
    'GET|HEAD api/version' => 'version for the updater/ef_bridge, no secrets',
    'GET|HEAD login' => 'login page',
    'GET|HEAD auth/discord' => 'OAuth redirect',
    'GET|HEAD auth/discord/callback' => 'OAuth callback (state checked)',
    'GET|HEAD auth/callback' => 'legacy OAuth callback alias',
    'GET|POST|HEAD invite' => 'invitation code entry before an account exists',
    'GET|HEAD auth/tablet' => 'one-time tablet token is the credential (issued only with the API key)',
    'GET|HEAD install' => 'installer, closed after installation',
    'POST install' => 'installer, install token, throttled',
    'GET|HEAD branding/{file}' => 'logo/favicon on the login page, fixed file names',
    'GET|HEAD storage/documents/{file}' => 'legacy public PDFs by unguessable public_id (P3)',
    'GET|HEAD storage/kb-images/{file}' => 'knowledge base images, public with the KB switch',
    'GET|HEAD lexicon' => 'public knowledge base (module switch), guest-throttled',
    'GET|HEAD lexicon/{entry}' => 'public knowledge base entry (policy hides non-public entries)',
    'GET|HEAD api/calendar/ical/{token}' => 'iCal feed, hashed token is the credential',
    'POST api/federation/pair' => 'pairing with a one-time hashed code, throttled',
    'GET|HEAD enotf/schnittstelle/login' => 'clinic login form',
    'POST enotf/schnittstelle/login' => 'clinic access code, 5/min per IP',
    'POST enotf/schnittstelle/logout' => 'ends the clinic session',
    'GET|HEAD enotf/schnittstelle/klinikcode' => 'clinic code form',
    'POST enotf/schnittstelle/klinikcode' => 'clinic code, 10/min per IP',
    'GET|HEAD enotf/schnittstelle/protocol' => 'shows only the protocol bound to a valid, unexpired clinic code in the session',
    'ANY settings/organisation' => 'redirect to /settings/system/config (auth there)',
    'GET|POST|HEAD api/character/get-session-id' => '410, removed',
    'ANY api/enotf/{rest}' => '410, legacy',
    'ANY api/hospitals/{rest}' => '410, legacy',
    'ANY api/hospital-availability-{rest}' => '410, legacy',
    'ANY api/klinik/{rest}' => '410, legacy',
    'ANY api/generate-klinikcode' => '410, legacy',
    'ANY api/pois/departments-sort' => '410, legacy',
];

const CSRF_FREE_ROUTES = ['GET|HEAD healthz', 'GET|HEAD storage/documents/{file}', 'GET|HEAD branding/{file}', 'GET|HEAD storage/kb-images/{file}'];

beforeEach(fn () => app(Kernel::class));

function routeKey(RoutingRoute $route): string
{
    $methods = $route->methods();

    return ($methods === Router::$verbs ? 'ANY' : implode('|', $methods)).' '.$route->uri();
}

/** @return list<string> resolved middleware class names, exclusions applied */
function routeMiddleware(RoutingRoute $route): array
{
    return array_map(fn ($m) => is_string($m) ? explode(':', $m)[0] : 'Closure', app('router')->gatherRouteMiddleware($route));
}

it('protects every route with auth middleware or lists it as public with a reason', function () {
    $public = [];
    foreach (Route::getRoutes() as $route) {
        if (array_intersect(routeMiddleware($route), AUTH_MIDDLEWARE) === []) {
            $public[] = routeKey($route);
        }
    }

    sort($public);
    $expected = array_keys(PUBLIC_ROUTES);
    sort($expected);
    expect($public)->toBe($expected);
});

it('verifies CSRF everywhere except on known read-only routes', function () {
    expect((fn () => static::$neverVerify)->bindTo(null, PreventRequestForgery::class)())->toBe([]);

    $skipped = [];
    foreach (Route::getRoutes() as $route) {
        if (in_array('web', $route->gatherMiddleware(), true) && ! in_array(PreventRequestForgery::class, routeMiddleware($route), true)) {
            $skipped[] = routeKey($route);
        }
    }

    expect(array_values(array_diff($skipped, ['GET|POST|HEAD broadcasting/auth'])))->toEqualCanonicalizing(CSRF_FREE_ROUTES);
});
