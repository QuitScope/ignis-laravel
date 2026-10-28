<?php

use App\Update\DockerDriver;
use App\Update\UpdateException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

const DD_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['ignis.update.updater_url' => 'http://updater:8099', 'ignis.update.updater_secret' => DD_SECRET]);
});

function ddReason(Closure $fn): ?string
{
    try {
        $fn();
    } catch (UpdateException $e) {
        return $e->reason;
    }

    return null;
}

it('signs body and timestamp with HMAC-SHA256 and sends only version and skip_trial', function () {
    Http::fake(['http://updater:8099/start' => Http::response(['ok' => true], 202)]);
    (new DockerDriver)->start('2.0.0', true);

    Http::assertSent(function (Request $r) {
        $ts = $r->header('X-Ignis-Timestamp')[0];
        $nonce = $r->header('X-Ignis-Nonce')[0];
        $expected = hash_hmac('sha256', $ts."\n".$nonce."\nPOST\n/start\n".$r->body(), DD_SECRET);

        return $r->url() === 'http://updater:8099/start'
            && json_decode($r->body(), true) === ['version' => '2.0.0', 'skip_trial' => true]
            && abs((int) $ts - time()) <= 2
            && preg_match('/^[0-9a-f]{32}$/', $nonce) === 1
            && hash_equals($expected, $r->header('X-Ignis-Signature')[0])
            && DockerDriver::signature(DD_SECRET, $ts, $nonce, 'POST', '/start', $r->body()) === $expected
            && ! str_contains(json_encode($r->headers()), DD_SECRET);
    });
});

it('rollback and ping send their action only', function () {
    Http::fake(['http://updater:8099/rollback' => Http::response(['ok' => true], 202), 'http://updater:8099/ping' => Http::response(['ok' => true, 'running' => true])]);
    $d = new DockerDriver;
    $d->rollback();
    expect($d->ping())->toBe(['running' => true])->and($d->ownerAlive())->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->url() === 'http://updater:8099/rollback' && json_decode($r->body(), true) === ['action' => 'rollback']);
    Http::assertSent(fn (Request $r) => $r->url() === 'http://updater:8099/ping' && json_decode($r->body(), true) === ['action' => 'ping']);
});

it('is configured only with an http url and a secret of at least 32 characters', function (?string $url, ?string $secret, bool $ok) {
    config(['ignis.update.updater_url' => $url, 'ignis.update.updater_secret' => $secret]);
    expect((new DockerDriver)->configured())->toBe($ok);
})->with([
    [null, DD_SECRET, false],
    ['http://updater:8099', null, false],
    ['http://updater:8099', 'short', false],
    ['ftp://updater', DD_SECRET, false],
    ['http://updater:8099', DD_SECRET, true],
]);

it('rejects bad signatures and old or future timestamps with a constant-time compare', function () {
    $body = '{"action":"ping"}';
    $now = 1_800_000_000;
    $n = str_repeat('ab', 16);
    $sig = DockerDriver::signature(DD_SECRET, (string) $now, $n, 'POST', '/ping', $body);
    $at = fn (int $t) => DockerDriver::verify(DD_SECRET, (string) $t, $n, DockerDriver::signature(DD_SECRET, (string) $t, $n, 'POST', '/ping', $body), 'POST', '/ping', $body, $now);
    expect(DockerDriver::verify(DD_SECRET, (string) $now, $n, $sig, 'POST', '/ping', $body, $now))->toBeTrue()
        ->and($at($now - 60))->toBeTrue()->and($at($now - 61))->toBeFalse()->and($at($now + 61))->toBeFalse()
        ->and(DockerDriver::verify(DD_SECRET, (string) $now, $n, $sig, 'POST', '/start', $body, $now))->toBeFalse()
        ->and(DockerDriver::verify(DD_SECRET, (string) $now, $n, $sig, 'POST', '/ping', $body.' ', $now))->toBeFalse()
        ->and(DockerDriver::verify(DD_SECRET, (string) $now, str_repeat('cd', 16), $sig, 'POST', '/ping', $body, $now))->toBeFalse()
        ->and(DockerDriver::verify(DD_SECRET, (string) $now, null, $sig, 'POST', '/ping', $body, $now))->toBeFalse()
        ->and(DockerDriver::verify(DD_SECRET, (string) $now, $n, strtoupper($sig), 'POST', '/ping', $body, $now))->toBeFalse()
        ->and(DockerDriver::verify(DD_SECRET, '1.8e9', $n, $sig, 'POST', '/ping', $body, $now))->toBeFalse()
        ->and(DockerDriver::verify('x', (string) $now, $n, DockerDriver::signature('x', (string) $now, $n, 'POST', '/ping', $body), 'POST', '/ping', $body, $now))->toBeFalse();
    expect(file_get_contents((new ReflectionClass(DockerDriver::class))->getFileName()))->toContain('hash_equals(');
});

it('maps updater answers to reasons without leaking url, secret or foreign text', function (Closure $fake, string $reason) {
    Http::fake(['http://updater:8099/*' => $fake]);
    $e = null;
    try {
        (new DockerDriver)->start('2.0.0', false);
    } catch (UpdateException $e) {
    }
    expect($e)->not->toBeNull()->and($e->reason)->toBe($reason)
        ->and($e->getMessage())->not->toContain(DD_SECRET)->not->toContain('updater:8099')->not->toContain('<script>');
})->with([
    'unreachable' => [fn () => throw new ConnectionException('cURL error 6: Could not resolve host: updater '.DD_SECRET), 'updater_unreachable'],
    'auth' => [fn () => Http::response(['reason' => 'auth'], 401), 'updater_auth'],
    'busy' => [fn () => Http::response(['reason' => 'busy'], 409), 'busy'],
    'refused with known reason' => [fn () => Http::response(['reason' => 'signature', 'message' => 'The release signature is missing or invalid; refused. password='.DD_SECRET], 422), 'signature'],
    'refused with odd reason' => [fn () => Http::response(['reason' => '<script>', 'message' => '<script>'], 422), 'updater_failed'],
    'server error' => [fn () => Http::response('boom '.DD_SECRET, 500), 'updater_failed'],
]);

it('treats an unreachable updater as alive (a start is refused, never doubled)', function () {
    Http::fake(['http://updater:8099/*' => fn () => throw new ConnectionException('down')]);
    expect((new DockerDriver)->ownerAlive())->toBeTrue()
        ->and(ddReason(fn () => (new DockerDriver)->ping()))->toBe('updater_unreachable');
});

it('refuses to send anything when not configured', function () {
    config(['ignis.update.updater_secret' => '']);
    expect(ddReason(fn () => (new DockerDriver)->start('2.0.0', false)))->toBe('driver_docker');
    Http::assertNothingSent();
});
