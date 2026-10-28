<?php

use App\Auth\ApiKey;
use App\Http\LegacyUrlMap;
use App\Http\Middleware\NormalizeLegacyPath;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/**
 * Runs only NormalizeLegacyPath on a request whose raw REQUEST_URI is exactly $uri (the test
 * client would normalise `//host` or `b[]=`). Returns [response, request handed to $next or null].
 *
 * @return array{Response, Request|null}
 */
function normalize(string $method, string $uri, ?Request $request = null): array
{
    $request ??= Request::create('/', $method);
    $request->server->set('REQUEST_URI', $uri);
    $request->server->set('QUERY_STRING', (string) parse_url($uri, PHP_URL_QUERY));
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
    $request->query->replace($query);
    $seen = null;
    $response = (new NormalizeLegacyPath)->handle($request, function (Request $r) use (&$seen) {
        $seen = $r;

        return new Response('next');
    });

    return [$response, $seen];
}

function echoRoute(string $method, string $uri): void
{
    Route::match([$method], $uri, fn (Request $r) => [
        'path' => $r->path(),
        'url' => $r->url(),
        'fullUrl' => $r->fullUrl(),
        'helper' => request()->path(),
        'facade' => RequestFacade::path(),
        'method' => $r->method(),
    ]);
}

/** Counts duplicate() calls: the middleware's only way to build a rewritten request. */
final class DuplicateSpyRequest extends Request
{
    public int $duplicates = 0;

    public function duplicate(?array $query = null, ?array $request = null, ?array $attributes = null, ?array $cookies = null, ?array $files = null, ?array $server = null): static
    {
        $this->duplicates++;

        return parent::duplicate($query, $request, $attributes, $cookies, $files, $server);
    }
}

// ----- .php suffix -----

it('lets ef_bridge POST /api/tablet/login-token.php reach the controller with its JSON body', function () {
    $key = app(ApiKey::class)->current();
    app(Settings::class)->set('auth.tablet_login', true);
    User::factory()->create(['discord_id' => '123456789012345678']);

    $plain = $this->postJson('/api/tablet/login-token', ['discord_id' => '123456789012345678'], ['X-API-Key' => $key]);
    $legacy = $this->postJson('/api/tablet/login-token.php', ['discord_id' => '123456789012345678'], ['X-API-Key' => $key]);

    $legacy->assertOk();
    expect(array_keys($legacy->json()))->toBe(array_keys($plain->json()))
        ->and($legacy->json('success'))->toBeTrue()
        ->and($legacy->json('login_url'))->toBe('http://localhost/auth/tablet');

    $this->postJson('/api/tablet/login-token.php', ['discord_id' => 'x'], ['X-API-Key' => $key])
        ->assertStatus(422)->assertJson(['error' => 'invalid_discord_id']);
});

it('lets the old Discord redirect URI /auth/callback.php reach the callback', function () {
    $s = app(Settings::class);
    $s->set('discord.client_id', '123456789012345678');
    $s->set('discord.client_secret', 'client-secret-value', encrypted: true);

    $this->withSession(['state' => 'expected'])->get('/auth/callback.php?code=x&state=y')
        ->assertRedirect('/login')
        ->assertSessionHas('auth.error', 'auth.error.discord_failed');
});

it('rewrites tablet pages under /einsatz/*.php to /firetab internally, query kept', function () {
    echoRoute('GET', '/firetab/list');

    $this->get('/einsatz/list.php?a=1')->assertOk()->assertExactJson([
        'path' => 'firetab/list',
        'url' => 'http://localhost/firetab/list',
        'fullUrl' => 'http://localhost/firetab/list?a=1',
        'helper' => 'firetab/list',
        'facade' => 'firetab/list',
        'method' => 'GET',
    ]);
});

it('rewrites a bare /einsatz.php internally like /einsatz, without a redirect', function () {
    foreach (['/einsatz.php', '/einsatz'] as $uri) {
        echoRoute('GET', '/firetab');

        $this->get($uri)->assertOk()->assertJson(['path' => 'firetab']);
    }
});

it('rewrites eNOTF pages and the billing endpoint internally, for GET and POST, query kept', function () {
    foreach ([
        ['GET', '/enotf/overview.php?enr=1', 'enotf/overview'],
        ['POST', '/enotf/protocol/save.php', 'enotf/protocol/save'],
        ['POST', '/api/enotf/billing.php', 'api/enotf/billing'],
        ['POST', '/api/enotf-billing', 'api/enotf/billing'],
    ] as [$method, $uri, $path]) {
        echoRoute($method, '/'.$path);

        $this->call($method, $uri)->assertOk()->assertJson(['path' => $path, 'method' => $method]);
    }
});

it('leaves /index.php and unknown paths alone', function () {
    [, $seen] = normalize('GET', '/index.php');
    expect($seen?->getRequestUri())->toBe('/index.php');

    $this->get('/does-not-exist.php')->assertNotFound();
    $this->get('/does-not-exist')->assertNotFound();
    $this->get('/.php')->assertNotFound();
});

// ----- German paths -----

it('redirects GET and HEAD on German paths permanently', function (string $method) {
    $r = $this->call($method, '/benutzer');
    $r->assertStatus(301);
    expect($r->headers->get('Location'))->toBe('/users');

    [$r] = normalize($method, '/benutzer/');
    expect($r->getStatusCode())->toBe(301)->and($r->headers->get('Location'))->toBe('/users/');
})->with(['GET', 'HEAD']);

it('rewrites other methods on German paths internally instead of redirecting', function () {
    echoRoute('POST', '/users/x');

    $this->post('/benutzer/x')->assertOk()->assertJson(['path' => 'users/x', 'method' => 'POST']);
});

it('rewrites German API paths internally, also for GET', function () {
    echoRoute('GET', '/api/calendar/events');

    $this->getJson('/api/kalender/events.php')->assertOk()->assertJson(['path' => 'api/calendar/events']);
});

// ----- legacy English pages -----

it('redirects legacy English pages to the current ones', function () {
    foreach ([
        ['/index', '/'],
        ['/settings/dashboard/index', '/settings/dashboard'],
        ['/users/list', '/users'],
        ['/users/list.php', '/users'],
        ['/users/roles/index', '/users/roles'],
        ['/users/registration-codes', '/users/invitations'],
        ['/benutzer/list', '/users'],
    ] as [$from, $to]) {
        $r = $this->get($from);
        $r->assertStatus(301);
        expect($r->headers->get('Location'))->toBe($to);
    }
});

it('only redirects GET and HEAD from the legacy page table', function () {
    $this->post('/users/list')->assertNotFound();
    $this->put('/logout')->assertStatus(405);
});

it('redirects GET /logout to /login with 302 and without logging out', function () {
    $user = User::factory()->create();

    $r = $this->actingAs($user)->get('/logout');
    $r->assertStatus(302);
    expect($r->headers->get('Location'))->toBe('/login');
    $this->assertAuthenticatedAs($user);
});

it('does not touch paths that already match a route', function () {
    foreach (['/users', '/users/roles', '/auth/callback', '/login', '/invite', '/users/audit-log', '/api/health'] as $uri) {
        [, $seen] = normalize('GET', $uri);
        expect($seen)->not->toBeNull()->and($seen?->getRequestUri())->toBe($uri);
    }
});

it('none of the legacy page keys is an existing GET route', function () {
    foreach (array_keys(LegacyUrlMap::redirects()) as $from) {
        if (str_ends_with($from, '/')) {
            continue;
        }
        expect(fn () => Route::getRoutes()->match(Request::create($from)))
            ->toThrow(HttpException::class);
    }
    foreach (LegacyUrlMap::patternRedirects() as [, , $sample]) {
        expect(fn () => Route::getRoutes()->match(Request::create($sample)))->toThrow(HttpException::class);
    }
});

// ----- raw URI handling and security -----

it('keeps the query string byte for byte and percent-encoding untouched', function () {
    [$r] = normalize('GET', '/benutzer?a=1&b[]=2');
    expect($r->headers->get('Location'))->toBe('/users?a=1&b[]=2');

    [$r] = normalize('GET', '/benutzer/%C3%A4%2F?x=%20&y');
    expect($r->headers->get('Location'))->toBe('/users/%C3%A4%2F?x=%20&y');

    [, $seen] = normalize('POST', '/einsatz/a%2Fb.php?a=1&b[]=2');
    expect($seen?->getRequestUri())->toBe('/firetab/a%2Fb?a=1&b[]=2')
        ->and($seen?->getPathInfo())->toBe('/firetab/a%2Fb')
        ->and($seen?->query->all())->toBe(['a' => '1', 'b' => ['2']]);
});

it('never redirects outside the host', function () {
    foreach ([
        '//evil.example/x',
        '//evil.example/x.php',
        '/\\evil',
        '/%2f%2fevil',
        '//benutzer',
        '//benutzer//evil.example',
        '///users/list',
        '/benutzer/\\evil.example',
        '//index',
    ] as $uri) {
        foreach (['GET', 'HEAD', 'POST'] as $method) {
            [$r, $seen] = normalize($method, $uri);
            $location = $r->headers->get('Location');
            expect($location === null || preg_match('#^/(?![/\\\\])#', $location) === 1)->toBeTrue("$method $uri -> $location");
            if ($seen !== null) {
                expect($seen->getHost())->toBe('localhost');
            }
        }
    }
});

it('passes ordinary paths on as the very same request, without building a copy', function () {
    foreach ([
        ['GET', '/'],
        ['GET', '/healthz'],
        ['GET', '/login'],
        ['GET', '/users'],
        ['GET', '/users/5/edit'],
        ['POST', '/api/tablet/login-token'],
        ['GET', '/index.php'],
        ['GET', '/auth/callback?code=x.php'],
        ['POST', '/users/list'],
    ] as [$method, $uri]) {
        $request = DuplicateSpyRequest::create('/', $method);
        $request->duplicates = 0;
        [$r, $seen] = normalize($method, $uri, $request);
        expect($seen)->toBe($request)->and($request->duplicates)->toBe(0)->and($r->getContent())->toBe('next');
    }
});

it('does build a copy when it rewrites (control for the spy above)', function () {
    $request = DuplicateSpyRequest::create('/', 'POST');
    $request->duplicates = 0;
    [, $seen] = normalize('POST', '/benutzer/x', $request);
    expect($seen)->not->toBe($request)->and($request->duplicates)->toBe(1);
});

it('keeps method, body, headers, cookies, attributes and files on the rewritten request', function () {
    $file = UploadedFile::fake()->createWithContent('a.txt', 'hello');
    $request = Request::create('/', 'POST', [], ['c' => 'v'], ['f' => $file], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_INERTIA' => 'true',
        'HTTP_X_API_KEY' => 'k',
    ], '{"discord_id":"1"}');
    $request->attributes->set('a', 'b');
    $request->headers->set('X-Added', 'later');

    [, $seen] = normalize('POST', '/api/emd/sync.php?q=1', $request);

    expect($seen)->not->toBe($request)
        ->and($seen?->path())->toBe('api/emd/sync')
        ->and($seen?->method())->toBe('POST')
        ->and($seen?->json()->all())->toBe(['discord_id' => '1'])
        ->and($seen?->getContent())->toBe('{"discord_id":"1"}')
        ->and($seen?->header('X-Inertia'))->toBe('true')
        ->and($seen?->header('X-API-Key'))->toBe('k')
        ->and($seen?->header('X-Added'))->toBe('later')
        ->and($seen?->cookie('c'))->toBe('v')
        ->and($seen?->file('f')?->getClientOriginalName())->toBe('a.txt')
        ->and($seen?->file('f')?->getSize())->toBe(5)
        ->and($seen?->attributes->get('a'))->toBe('b')
        ->and($seen?->query('q'))->toBe('1');
});

it('delivers an uploaded file through a rewrite', function () {
    Route::post('/firetab/upload', fn (Request $r) => [
        'name' => $r->file('doc')?->getClientOriginalName(),
        'size' => $r->file('doc')?->getSize(),
        'content' => $r->file('doc')?->getContent(),
    ]);

    $this->call('POST', '/einsatz/upload.php', ['note' => 'x'], [], ['doc' => UploadedFile::fake()->createWithContent('report.txt', 'hello')])
        ->assertOk()->assertExactJson(['name' => 'report.txt', 'size' => 5, 'content' => 'hello']);
});

it('keeps session and Inertia headers through a rewrite in the full stack', function () {
    $user = User::factory()->create();
    Route::middleware('web')->post('/firetab/me', fn (Request $r) => [
        'user' => $r->user()?->id,
        'inertia' => $r->header('X-Inertia'),
        'session' => $r->session()->get('k'),
    ]);

    $this->actingAs($user)->withSession(['k' => 'v'])
        ->post('/einsatz/me.php', [], ['X-Inertia' => 'true'])
        ->assertOk()->assertExactJson(['user' => $user->id, 'inertia' => 'true', 'session' => 'v']);
});

// ----- FiveM / CEF -----

function cefRoute(): void
{
    Route::middleware(['web', 'fivem'])->get('/cef-probe', fn () => response('ok')
        ->header('Content-Security-Policy', "default-src 'self'")
        ->header('X-Frame-Options', 'DENY'));
}

it('removes CSP and X-Frame-Options for CitizenFX', function () {
    foreach (['CitizenFX/1', 'Mozilla/5.0 (Windows NT 10.0) citizenfx/1.0 Chrome/103'] as $agent) {
        cefRoute();
        $r = $this->get('/cef-probe', ['User-Agent' => $agent]);
        expect($r->headers->has('Content-Security-Policy'))->toBeFalse()
            ->and($r->headers->has('X-Frame-Options'))->toBeFalse();
    }
});

it('keeps CSP and X-Frame-Options for normal browsers', function () {
    cefRoute();
    $r = $this->get('/cef-probe', ['User-Agent' => 'Mozilla/5.0']);
    expect($r->headers->get('Content-Security-Policy'))->toBe("default-src 'self'")
        ->and($r->headers->get('X-Frame-Options'))->toBe('DENY');
});

function tabletCookie(string $url, string $agent): ?Cookie
{
    app(Settings::class)->set('auth.tablet_login', true);

    return test()->get($url, ['User-Agent' => $agent])->getCookie(config('session.cookie'), false);
}

it('sets the session cookie SameSite=None; Secure for CitizenFX over HTTPS on /auth/tablet', function () {
    $c = tabletCookie('https://localhost/auth/tablet?token=x', 'CitizenFX/1');
    expect($c?->getSameSite())->toBe('none')->and($c?->isSecure())->toBeTrue();
});

it('leaves the session cookie unchanged for normal browsers over HTTPS', function () {
    $c = tabletCookie('https://localhost/auth/tablet?token=x', 'Mozilla/5.0');
    expect($c?->getSameSite())->toBe('lax')->and(config('session.same_site'))->toBe('lax')->and(config('session.secure'))->toBeNull();
});

it('leaves the session cookie unchanged for CitizenFX over plain HTTP', function () {
    $c = tabletCookie('http://localhost/auth/tablet?token=x', 'CitizenFX/1');
    expect($c?->getSameSite())->toBe('lax')->and($c?->isSecure())->toBeFalse();
});

it('strips CSP headers on /auth/tablet for CitizenFX', function () {
    app(Settings::class)->set('auth.tablet_login', true);
    $r = $this->get('/auth/tablet?token=x', ['User-Agent' => 'CitizenFX/1']);
    $r->assertRedirect();
    expect($r->headers->has('X-Frame-Options'))->toBeFalse()
        ->and(Route::getRoutes()->match(Request::create('/auth/tablet'))->gatherMiddleware())->toContain('fivem');
});

// ----- SecurityHeaders (X-Frame-Options moved from nginx into the app) -----

it('sends exactly one X-Frame-Options: SAMEORIGIN to normal browsers', function () {
    foreach (['/login', '/auth/tablet?token=x', '/api/version', '/does-not-exist'] as $uri) {
        app(Settings::class)->set('auth.tablet_login', true);
        $r = $this->get($uri, ['User-Agent' => 'Mozilla/5.0']);
        expect($r->headers->all('x-frame-options'))->toBe(['SAMEORIGIN']);
    }
});

it('sends no X-Frame-Options to CitizenFX, also on early responses', function () {
    foreach (['/auth/tablet?token=x', '/benutzer', '/logout', '/login'] as $uri) {
        app(Settings::class)->set('auth.tablet_login', true);
        expect($this->get($uri, ['User-Agent' => 'CitizenFX/1'])->headers->has('X-Frame-Options'))->toBeFalse();
    }
});

it('sends X-Frame-Options on legacy redirects to normal browsers', function () {
    foreach (['/benutzer', '/settings/dashboard/index', '/logout'] as $uri) {
        $r = $this->get($uri, ['User-Agent' => 'Mozilla/5.0']);
        expect($r->isRedirect())->toBeTrue()->and($r->headers->all('x-frame-options'))->toBe(['SAMEORIGIN']);
    }
});

it('keeps an X-Frame-Options the app set itself', function () {
    cefRoute();
    expect($this->get('/cef-probe')->headers->all('x-frame-options'))->toBe(['DENY']);
});
