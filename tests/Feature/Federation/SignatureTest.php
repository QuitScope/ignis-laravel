<?php

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Support\PeerHttp;
use Modules\Federation\Support\PeerSignature;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(Settings::class)->set('modules.federation.enabled', true);
    $this->link = (new FederationLink)->forceFill([
        'instance_id' => (string) Str::uuid(), 'name' => 'Peer', 'url' => 'https://peer.example',
        'secret_outgoing' => 'out-'.Str::random(40), 'secret_incoming' => 'in-'.Str::random(40),
    ]);
    $this->link->save();
});

function fed_sign(FederationLink $link, string $pathWithQuery): array
{
    return ['X-Ignis-Instance' => $link->instance_id] + PeerSignature::headers((new FederationLink)->forceFill(['secret_outgoing' => $link->secret_incoming]), 'GET', $pathWithQuery);
}

it('accepts a signed request once and confirms the link', function () {
    $h = fed_sign($this->link, '/api/federation/handshake?b=2&a=1');

    $this->get('/api/federation/handshake?a=1&b=2', $h)->assertOk()->assertJsonPath('version', 2);
    expect($this->link->fresh()->confirmed_at)->not->toBeNull();

    $this->get('/api/federation/handshake?a=1&b=2', $h)->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
    $this->travel(PeerSignature::WINDOW * 2 + 30)->seconds();
    expect(Cache::has("federation:nonce:{$this->link->id}:{$h['X-Ignis-Nonce']}"))->toBeTrue();
});

it('locks out failures per ip and instance, not the whole ip', function () {
    $h = fed_sign($this->link, '/api/federation/handshake');
    foreach (range(1, 30) as $_) {
        $this->get('/api/federation/handshake', ['X-Ignis-Instance' => 'attacker'] + $h);
    }
    $this->get('/api/federation/handshake', ['X-Ignis-Instance' => 'attacker'] + $h)->assertStatus(429);
    $this->get('/api/federation/handshake', $h)->assertOk();
});

it('refuses forged, stale, unknown, inactive and unsigned requests', function () {
    $h = fed_sign($this->link, '/api/federation/handshake?a=1');

    $this->get('/api/federation/handshake?a=2', $h)->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
    $this->get('/api/federation/handshake?a=1', ['X-Ignis-Signature' => str_repeat('0', 64)] + $h)->assertUnauthorized();
    $this->get('/api/federation/handshake?a=1', ['X-Ignis-Instance' => (string) Str::uuid()] + $h)->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
    $this->get('/api/federation/handshake')->assertUnauthorized()->assertJsonPath('error', 'protocol_v2_required');

    $this->travel(301)->seconds();
    $this->get('/api/federation/handshake?a=1', $h)->assertUnauthorized()->assertJsonPath('error', 'clock_skew');
    $this->get('/api/federation/handshake?a=1', ['X-Ignis-Instance' => (string) Str::uuid()] + $h)->assertJsonPath('error', 'clock_skew');
    $this->travelBack();

    $this->link->forceFill(['is_active' => false])->save();
    $this->get('/api/federation/handshake?a=1', $h)->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
    expect($this->link->fresh()->confirmed_at)->toBeNull();
});

it('is 404 while the module is off', function () {
    app(Settings::class)->set('modules.federation.enabled', false);
    $this->get('/api/federation/handshake')->assertNotFound();
});

it('signs outgoing requests so the receiving side accepts them', function () {
    PeerHttp::resolveUsing(fn () => ['93.184.216.34']);
    Http::fake(function (ClientRequest $sent) {
        $r = Request::create($sent->url(), 'GET', server: collect($sent->headers())->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v[0]])->all());
        $receiver = (new FederationLink)->forceFill(['secret_incoming' => $this->link->secret_outgoing]);
        $receiver->id = 99;

        return Http::response(['ok' => PeerSignature::verify($r, $receiver) === null]);
    });

    $res = PeerHttp::send($this->link, 'GET', '/api/federation/personnel', ['limit' => 100, 'after' => 'x'], sign: $this->link);
    expect($res->json('ok'))->toBeTrue();
    PeerHttp::resolveUsing(null);
});
