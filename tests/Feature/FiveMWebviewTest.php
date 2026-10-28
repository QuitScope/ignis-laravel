<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware(['web', 'fivem'])->group(function () {
        Route::get('/fivem-probe', fn () => response('ok')->header('Content-Security-Policy', "default-src 'self'"));
        Route::get('/fivem-probe.json', fn () => response()->json(['ok' => true])->header('Content-Security-Policy', "default-src 'self'"));
    });
    Route::middleware('web')->get('/not-fivem', fn () => response('ok'));
});

/** @param array<string, string> $headers */
function fivemGet(string $url, array $headers = []): TestResponse
{
    return test()->get($url, ['User-Agent' => 'Mozilla/5.0 Chrome/103', ...$headers]);
}

function expectCrossSiteCookies(TestResponse $r): void
{
    foreach ([config('session.cookie'), 'XSRF-TOKEN'] as $name) {
        $c = $r->getCookie($name, false);
        expect($c)->not->toBeNull()
            ->and($c->getSameSite())->toBe('none', $name)
            ->and($c->isSecure())->toBeTrue($name);
    }
}

function expectNoFrameHeaders(TestResponse $r): void
{
    expect($r->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($r->headers->has('X-Frame-Options'))->toBeFalse();
}

$signals = [
    'CitizenFX user agent' => [['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) CitizenFX/1.0 Chrome/103']],
    'Sec-Fetch-Dest: iframe' => [['Sec-Fetch-Dest' => 'iframe']],
    'X-Iframe-Request' => [['X-Iframe-Request' => '1']],
];

it('sets session and XSRF-TOKEN cookies SameSite=None; Secure over HTTPS and drops frame headers', function (array $headers) {
    $r = fivemGet('https://localhost/fivem-probe', $headers)->assertOk();
    expectCrossSiteCookies($r);
    expectNoFrameHeaders($r);
})->with($signals);

it('does the same for JSON responses', function (array $headers) {
    $r = fivemGet('https://localhost/fivem-probe.json', $headers)->assertOk()->assertExactJson(['ok' => true]);
    expectCrossSiteCookies($r);
    expectNoFrameHeaders($r);
})->with($signals);

it('keeps Lax cookies over plain HTTP, but still drops the frame headers', function (array $headers) {
    $r = fivemGet('http://localhost/fivem-probe', $headers)->assertOk();
    foreach ([config('session.cookie'), 'XSRF-TOKEN'] as $name) {
        expect($r->getCookie($name, false)?->getSameSite())->toBe('lax')->and($r->getCookie($name, false)?->isSecure())->toBeFalse();
    }
    expectNoFrameHeaders($r);
})->with($signals);

it('leaves normal browsers alone', function (array $headers) {
    $r = fivemGet('https://localhost/fivem-probe', $headers)->assertOk();
    foreach ([config('session.cookie'), 'XSRF-TOKEN'] as $name) {
        expect($r->getCookie($name, false)?->getSameSite())->toBe('lax');
    }
    expect($r->headers->get('Content-Security-Policy'))->toBe("default-src 'self'")
        ->and($r->headers->all('x-frame-options'))->toBe(['SAMEORIGIN'])
        ->and(config('session.same_site'))->toBe('lax');
})->with([
    'no signal' => [[]],
    'Sec-Fetch-Dest: document' => [['Sec-Fetch-Dest' => 'document']],
    'empty X-Iframe-Request' => [['X-Iframe-Request' => '']],
]);

it('ignores the iframe headers outside fivem routes', function () {
    $r = fivemGet('https://localhost/not-fivem', ['Sec-Fetch-Dest' => 'iframe', 'X-Iframe-Request' => '1'])->assertOk();
    expect($r->getCookie(config('session.cookie'), false)?->getSameSite())->toBe('lax')
        ->and($r->headers->all('x-frame-options'))->toBe(['SAMEORIGIN']);
});

it('keeps recognising the session after the iframe navigation (XHR sends no iframe header)', function () {
    fivemGet('https://localhost/fivem-probe', ['Sec-Fetch-Dest' => 'iframe'])->assertOk();

    $r = fivemGet('https://localhost/fivem-probe.json', ['Sec-Fetch-Dest' => 'empty'])->assertOk();
    expectCrossSiteCookies($r);
    expectNoFrameHeaders($r);
});
