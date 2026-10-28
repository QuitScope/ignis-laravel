<?php

use Illuminate\Support\Facades\Http;
use Modules\Federation\Support\PeerHttp;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    PeerHttp::resolveUsing(fn (string $host) => match ($host) {
        'peer.example' => ['93.184.216.34', '2606:2800:220:1::1'],
        'rebind.example' => ['93.184.216.34', '10.0.0.5'],
        'internal.example' => ['192.168.1.10'],
        default => [],
    });
});

afterEach(fn () => PeerHttp::resolveUsing(null));

it('blocks unsafe peer urls (SSRF)', function () {
    foreach ([
        'http' => ['http://peer.example', 'invalid_url'],
        'userinfo' => ['https://u:p@peer.example', 'invalid_url'],
        'query' => ['https://peer.example/?x=1', 'invalid_url'],
        'dot-dot' => ['https://peer.example/a/../b', 'invalid_url'],
        'port' => ['https://peer.example:8443', 'blocked_address'],
        'loopback' => ['https://127.0.0.1', 'blocked_address'],
        'private' => ['https://10.0.0.1', 'blocked_address'],
        'metadata' => ['https://169.254.169.254', 'blocked_address'],
        'cgnat' => ['https://100.64.0.1', 'blocked_address'],
        'ipv6 loopback' => ['https://[::1]', 'blocked_address'],
        'ipv6 ula' => ['https://[fd00::1]', 'blocked_address'],
        'ipv4-mapped' => ['https://[::ffff:10.0.0.1]', 'blocked_address'],
        'private dns' => ['https://internal.example', 'blocked_address'],
        'one private address' => ['https://rebind.example', 'blocked_address'],
        'no address' => ['https://nowhere.example', 'unreachable'],
    ] as [$url, $code]) {
        expect(PeerHttp::check($url))->toBe($code);
    }
});

it('allows a public https peer, honours the allow-list and the private switch', function () {
    expect(PeerHttp::check('https://peer.example'))->toBeNull()
        ->and(PeerHttp::check('https://peer.example/intra/'))->toBeNull();

    config(['ignis.federation.allowed_hosts' => ['other.example']]);
    expect(PeerHttp::check('https://peer.example'))->toBe('blocked_address');

    config(['ignis.federation.allowed_hosts' => [], 'ignis.federation.allow_private' => true]);
    expect(PeerHttp::check('https://internal.example'))->toBeNull()
        ->and(PeerHttp::check('http://internal.example'))->toBe('invalid_url');
});

it('never follows redirects and reports codes, not content', function () {
    Http::fake([
        'peer.example/api/federation/handshake' => Http::response('', 302, ['Location' => 'https://169.254.169.254/']),
        'peer.example/api/federation/personnel' => Http::response('secret internals', 500),
        'peer.example/api/federation/enotf' => Http::response('Federation-Key fehlt', 401),
    ]);

    expect(PeerHttp::send('https://peer.example', 'GET', '/api/federation/handshake'))->toBe('redirect')
        ->and(PeerHttp::send('https://peer.example', 'GET', '/api/federation/personnel'))->toBe('http_500')
        ->and(PeerHttp::send('https://peer.example', 'GET', '/api/federation/enotf'))->toBe('peer_v1')
        ->and(PeerHttp::send('https://10.0.0.1', 'GET', '/api/federation/handshake'))->toBe('blocked_address');
    Http::assertSentCount(3);
});
