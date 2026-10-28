<?php

use App\Models\Personnel;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Federation\Http\Controllers\Api\FederatedIncidentController;
use Modules\Federation\Http\Controllers\Api\PersonnelController;
use Modules\Federation\Http\Controllers\Api\ProtocolController;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Support\Pairing;
use Modules\Federation\Support\PeerHttp;
use Modules\Federation\Support\PeerSignature;
use Modules\FireTab\Models\FireIncident;

uses(RefreshDatabase::class)->group('mariadb');

beforeEach(function () {
    app(Settings::class)->set('modules.federation.enabled', true);
    PeerHttp::resolveUsing(fn () => ['93.184.216.34']);
});

afterEach(fn () => PeerHttp::resolveUsing(null));

function fed_invite_code(): string
{
    return json_decode(base64_decode(strtr(Pairing::invite(), '-_', '+/')), true)['code'];
}

function fed_pair_body(array $over = []): array
{
    return $over + ['v' => 2, 'instance_id' => (string) Str::uuid(), 'name' => 'Peer B', 'url' => 'https://b.example', 'secret_for_you' => Str::random(64)];
}

it('pairs with a valid code exactly once and never echoes or audits it', function () {
    $code = fed_invite_code();
    $body = fed_pair_body(['code' => $code]);

    $res = $this->postJson('/api/federation/pair', $body)->assertOk()->assertJsonPath('success', true);
    $link = FederationLink::query()->where('instance_id', $body['instance_id'])->sole();
    expect($res->getContent())->not->toContain($code)
        ->and($link->secret_outgoing)->toBe($body['secret_for_you'])
        ->and($link->secret_incoming)->toBe($res->json('secret_for_you'))
        ->and($link->provide_personnel || $link->provide_enotf || $link->provide_fire)->toBeFalse()
        ->and($link->confirmed_at)->toBeNull()
        ->and(DB::table('federation_links')->value('secret_outgoing'))->not->toContain($body['secret_for_you']);
    $audit = DB::table('activity_log')->get()->toJson();
    expect($audit)->toContain('Instanz gekoppelt')->not->toContain($code)->not->toContain($body['secret_for_you'])->not->toContain($link->secret_incoming);

    $this->postJson('/api/federation/pair', fed_pair_body(['code' => $code]))->assertForbidden()->assertJsonPath('error', 'invalid_code');
});

it('refuses missing, wrong and expired codes', function () {
    $code = fed_invite_code();

    $this->postJson('/api/federation/pair', fed_pair_body())->assertForbidden();
    $this->postJson('/api/federation/pair', fed_pair_body(['code' => Str::random(32)]))->assertForbidden();
    $this->travel(16)->minutes();
    $this->postJson('/api/federation/pair', fed_pair_body(['code' => $code]))->assertForbidden();
    expect(FederationLink::query()->count())->toBe(0);
});

it('replaces a half pairing but answers 409 for a confirmed link', function () {
    $id = (string) Str::uuid();
    $half = (new FederationLink)->forceFill(['instance_id' => $id, 'name' => 'Old', 'url' => 'https://old.example', 'secret_outgoing' => 'x', 'secret_incoming' => 'y', 'provide_enotf' => true]);
    $half->save();

    $this->postJson('/api/federation/pair', fed_pair_body(['instance_id' => $id, 'code' => fed_invite_code()]))->assertOk();
    expect($half->fresh())->name->toBe('Peer B')->url->toBe('https://b.example')->provide_enotf->toBeFalse();

    $half->forceFill(['confirmed_at' => now()])->save();
    $this->postJson('/api/federation/pair', fed_pair_body(['instance_id' => $id, 'code' => fed_invite_code()]))->assertStatus(409);
});

it('accepts a token on side B and confirms the link with a signed handshake', function () {
    $a = (string) Str::uuid();
    $token = rtrim(strtr(base64_encode(json_encode(['v' => 2, 'url' => 'https://a.example', 'instance_id' => $a, 'name' => 'A', 'code' => 'c0de'])), '+/', '-_'), '=');
    Http::fake([
        'a.example/api/federation/pair' => Http::response(['success' => true, 'instance_id' => $a, 'name' => 'Peer A', 'secret_for_you' => str_repeat('k', 64)]),
        'a.example/api/federation/handshake' => Http::response(['success' => true]),
    ]);

    $link = Pairing::accept($token);

    expect($link)->toBeInstanceOf(FederationLink::class)
        ->and($link->secret_outgoing)->toBe(str_repeat('k', 64))
        ->and($link->confirmed_at)->not->toBeNull();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/pair') && $r['code'] === 'c0de' && $r['secret_for_you'] === $link->secret_incoming);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/handshake') && $r->hasHeader('X-Ignis-Signature'));
    expect(Pairing::accept('garbage'))->toBe('invalid_token');
});

it('serves exactly the legacy field lists, tombstones and keyset pages', function () {
    $link = (new FederationLink)->forceFill(['instance_id' => (string) Str::uuid(), 'name' => 'P', 'url' => 'https://p.example', 'secret_outgoing' => 'o', 'secret_incoming' => Str::random(40),
        'provide_personnel' => true, 'provide_enotf' => true, 'provide_fire' => true]);
    $link->save();
    $get = function (string $path) use ($link) {
        $h = ['X-Ignis-Instance' => $link->instance_id] + PeerSignature::headers((new FederationLink)->forceFill(['secret_outgoing' => $link->secret_incoming]), 'GET', $path);

        return $this->get($path, $h)->assertOk()->json();
    };
    Personnel::factory()->count(2)->create();
    (new EnotfProtocol)->forceFill(['enr' => '1001', 'status' => ProtocolStatus::Released, 'patient_first_name' => 'Max', 'patient_last_name' => 'Muster'])->save();
    (new EnotfProtocol)->forceFill(['enr' => '1002'])->save();
    (new FireIncident)->forceFill(['number' => 'F-1', 'owner_name' => 'Eigentümer'])->save();
    $this->travel(1)->seconds();
    (new FireIncident)->forceFill(['number' => 'F-2'])->save();

    $p1 = $get('/api/federation/personnel?limit=1');
    expect(array_keys($p1['data'][0]))->toBe(PersonnelController::FIELDS)->and($p1['next'])->not->toBeNull();
    $p2 = $get('/api/federation/personnel?after='.$p1['next'].'&limit=1');
    expect($p2['data'][0]['id'])->not->toBe($p1['data'][0]['id'])->and($p2['next'])->toBeNull();

    $e = $get('/api/federation/enotf');
    expect(array_keys($e['data'][0]))->toBe(ProtocolController::FIELDS)->and($e['data'][0]['patname'])->toBe('Muster, Max')
        ->and($e['data'][1])->toBe(['id' => $e['data'][1]['id'], 'visible' => false]);

    $f = $get('/api/federation/fire-incidents')['data'];
    expect($f)->toHaveCount(1)->and(array_keys($f[0]))->toBe(FederatedIncidentController::FIELDS);

    $h = ['X-Ignis-Instance' => $link->instance_id] + PeerSignature::headers((new FederationLink)->forceFill(['secret_outgoing' => $link->secret_incoming]), 'GET', '/api/federation/enotf?after=%21%21');
    $this->get('/api/federation/enotf?after=%21%21', $h)->assertStatus(422);
});
