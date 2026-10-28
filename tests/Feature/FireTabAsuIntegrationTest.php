<?php

use App\Auth\ApiKey;
use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\FireTab\Models\AsuProtocol;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentLog;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Support\AsuState;

uses(RefreshDatabase::class);

it('runs the whole SCBA flow: Trupp, events, void, mayday, resend, derived state, legacy sync', function () {
    $crew = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $own = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE, 'name' => 'HLF 1']);
    $incident = (new FireIncident)->forceFill(['number' => 'E-200', 'location' => 'Hauptstr. 1', 'keyword' => 'B3', 'started_at' => now()]);
    $incident->save();
    (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $incident->id, 'vehicle_id' => $own->id, 'name' => $own->name])->save();
    $this->actingAs($crew)->withSession(['crew.firetab' => ['user_id' => $crew->id, 'vehicle_id' => $own->id, 'personnel_id' => $crew->personnel_id, 'since' => time()]]);
    $url = "/firetab/incidents/{$incident->id}/asu";

    $trupp = $this->postJson("$url/trupps", ['name' => 'AT 1', 'role' => 'angriff', 'expected_minutes' => 30,
        'members' => [['position' => 'tf', 'name' => 'Weber, M'], ['position' => 'tm1', 'name' => 'Krause, J']]])->assertCreated()->json('trupp_id');
    $tf = $this->getJson("$url/state")->json('trupps.0.members.0.id');

    $send = fn (string $type, array $extra = [], ?string $uuid = null) => $this->postJson("$url/events", ['trupp_id' => $trupp, 'type' => $type, 'client_uuid' => $uuid ?? (string) Str::uuid(), ...$extra])->assertOk();
    $send('angeschlossen', ['bar' => 300]);
    $send('druck', ['bar' => 250, 'member_id' => $tf]);
    $send('ziel', ['bar' => 240]);
    $send('rueckzug');
    $out = $send('draussen')->json('event_id');
    $this->postJson("$url/events/$out/void")->assertOk();

    $uuid = (string) Str::uuid();
    $note = json_encode(['member' => 'Weber, M', 'standort' => '1. OG', 'lage' => 'eingeklemmt']);
    $first = $send('mayday', ['note' => $note], $uuid)->assertJson(['duplicate' => false])->json('event_id');
    $send('mayday', ['note' => $note], $uuid)->assertJson(['duplicate' => true, 'event_id' => $first]);

    $state = $this->getJson("$url/state")->assertOk()->assertJson(['writable' => true, 'voided' => [$out]]);
    expect($state->json('events'))->toHaveCount(6);
    $events = array_map(fn (array $e) => $e + ['voided' => in_array($e['id'], $state->json('voided'), true)], $state->json('events'));
    $derived = AsuState::derive($state->json('trupps.0'), $events, $state->json('now'), ['consumption' => 10]);
    expect($derived)->toMatchArray(['phase' => 'rueckzug', 'tone' => 'mayday', 'startBar' => 300, 'zielBar' => 240, 'retreatBar' => 120, 'endAt' => null])
        ->and($derived['mayday'])->toMatchArray(['standort' => '1. OG', 'lage' => 'eingeklemmt'])
        ->and(FireIncidentLog::where('fire_incident_id', $incident->id)->where('action', 'like', 'asu_%')->orderBy('id')->pluck('action')->all())
        ->toBe(['asu_angeschlossen', 'asu_draussen', 'asu_mayday']);

    $this->call('POST', '/api/asu-sync', [], [], [], ['HTTP_X-API-Key' => app(ApiKey::class)->current(), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        json_encode(['type' => 'asu_protocol', 'data' => ['missionNumber' => 'E-200', 'supervisor' => 'Max', 'trupps' => [['name' => 'AT 1']]]]))
        ->assertOk()->assertJson(['action' => 'created', 'incident_id' => $incident->id]);
    expect(AsuProtocol::where('fire_incident_id', $incident->id)->sole()->payload['trupps'][0]['name'])->toBe('AT 1');
});
