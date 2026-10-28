<?php

use App\Auth\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\FireTab\Models\AsuProtocol;
use Modules\FireTab\Models\FireIncident;

uses(RefreshDatabase::class);

function asu_sync(array $data, array $extra = [], string $url = '/api/asu/sync', ?string $key = null): TestResponse
{
    $body = ['type' => 'asu_protocol', 'data' => array_merge(['missionNumber' => 'E-9', 'supervisor' => 'Max'], $data, $extra)];

    return test()->call('POST', $url, [], [], [], ['HTTP_X-API-Key' => $key ?? app(ApiKey::class)->current(), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body));
}

function asu_inc(): FireIncident
{
    $i = (new FireIncident)->forceFill(['number' => 'E-9']);
    $i->save();

    return $i;
}

it('upserts one row per supervisor, stores the payload unchanged and parses date and timestamp', function () {
    $inc = asu_inc();
    $payload = ['missionLocation' => 'Hauptstr. 1', 'missionDate' => '05.03.2025', 'timestamp' => '2025-04-13T08:38:43+02:00', 'trupps' => [['name' => 'Trupp 1', 'x' => [1, 2]]]];

    asu_sync($payload)->assertOk()->assertJson(['success' => true, 'action' => 'created', 'incident_id' => $inc->id]);
    asu_sync($payload, ['missionLocation' => 'Neu'], '/api/asu-sync')->assertOk()->assertJson(['action' => 'updated']);
    asu_sync([], ['supervisor' => 'Anna'])->assertOk()->assertJson(['action' => 'created']);

    expect(AsuProtocol::count())->toBe(2);
    $p = AsuProtocol::where('supervisor', 'Max')->sole();
    expect($p->mission_location)->toBe('Neu')
        ->and($p->mission_date->toDateString())->toBe('2025-03-05')
        ->and($p->recorded_at->utc()->format('Y-m-d H:i:s'))->toBe('2025-04-13 06:38:43')
        ->and($p->payload['trupps'])->toBe($payload['trupps'])
        ->and($p->payload['missionNumber'])->toBe('E-9');
    asu_sync(['missionDate' => '31.02.2025'], ['supervisor' => 'Bad'])->assertOk();
    expect(AsuProtocol::where('supervisor', 'Bad')->sole()->mission_date)->toBeNull();
});

it('needs the api key', function () {
    asu_inc();
    app(ApiKey::class)->current();
    asu_sync([], [], '/api/asu/sync', 'wrong')->assertForbidden();
    expect(AsuProtocol::count())->toBe(0);
});

it('answers 404 for unknown incidents and rejects oversized or malformed payloads', function () {
    asu_sync(['missionNumber' => 'nope'])->assertNotFound()->assertJson(['success' => false, 'error' => 'Einsatz nicht gefunden']);
    asu_inc();
    asu_sync(['blob' => str_repeat('x', 210 * 1024)])->assertStatus(413);
    asu_sync(['trupps' => 'x'])->assertStatus(400);
    expect(AsuProtocol::count())->toBe(0);
});
