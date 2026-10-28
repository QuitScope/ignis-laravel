<?php

use App\Auth\ApiKey;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FireTab\Models\AsuEvent;
use Modules\FireTab\Models\AsuProtocol;
use Modules\FireTab\Models\AsuTrupp;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

const P8B_KEYS = ['state', 'create', 'update', 'event', 'void', 'delete'];

const P8B_EXPECTED = [
    'guest' => ['*' => 401, 'sync' => 200],
    'no-session' => ['*' => 'crew', 'qmstate' => 403, 'sync' => 200],
    'crew' => ['*' => 403, 'state:O' => 200, 'state:Z' => 200, 'create:O' => 201, 'update:O' => 200, 'event:O' => 200, 'void:O' => 200, 'delete:O' => 200,
        'qmstate' => 200, 'sync' => 200],
    'qm' => ['*' => 'crew', 'qmstate' => 200, 'sync' => 200],
    'super-admin' => ['*' => 403, 'state:O' => 200, 'state:F' => 200, 'state:Z' => 200, 'create:O' => 201, 'update:O' => 200, 'event:O' => 200,
        'void:O' => 200, 'delete:O' => 200, 'qmstate' => 200, 'sync' => 200],
];

/** @return array{0: FireIncident, 1: AsuTrupp, 2: AsuTrupp, 3: AsuEvent} */
function p8b_incident(Vehicle $vehicle, string $number, array $over = []): array
{
    $i = (new FireIncident)->forceFill(['number' => $number, 'location' => 'Hauptstr. 1', 'keyword' => 'B2', 'started_at' => now(), ...$over]);
    $i->save();
    (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $i->id, 'vehicle_id' => $vehicle->id, 'name' => $vehicle->name])->save();
    $t = (new AsuTrupp)->forceFill(['fire_incident_id' => $i->id, 'name' => 'AT 1', 'role' => 'angriff', 'expected_minutes' => 30]);
    $t->save();
    $d = (new AsuTrupp)->forceFill(['fire_incident_id' => $i->id, 'name' => 'Leer', 'role' => 'angriff', 'expected_minutes' => 30]);
    $d->save();
    $e = (new AsuEvent)->forceFill(['fire_incident_id' => $i->id, 'trupp_id' => $t->id, 'type' => 'angeschlossen', 'bar' => 300,
        'client_uuid' => (string) Str::uuid(), 'occurred_at' => now(), 'received_at' => now()]);
    $e->save();

    return [$i, $t, $d, $e];
}

it('answers every ASU route with the exact status and leaves the right state', function (string $actor) {
    $mine = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $inc = ['O' => p8b_incident($mine, 'E-O'), 'F' => p8b_incident(Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]), 'E-F'),
        'Z' => p8b_incident($mine, 'E-Z', ['finalized_at' => now()])];

    $user = match ($actor) {
        'guest' => null,
        'no-session', 'crew' => User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]),
        'qm' => User::factory()->withRole(tap(Role::create(['name' => 'QM', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']))->syncPermissions(['fire.incident.qm']))
            ->create(['personnel_id' => Personnel::factory()->create()->id]),
        'super-admin' => User::factory()->superAdmin()->create(['personnel_id' => Personnel::factory()->create()->id]),
    };
    $session = in_array($actor, ['crew', 'super-admin'], true)
        ? ['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $mine->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]
        : [];

    $trupp = ['name' => 'AT 2', 'role' => 'sicherheit', 'expected_minutes' => 30, 'members' => [['position' => 'tf', 'name' => 'Weber, M']]];
    $requests = ['qmstate' => ['GET', "/firetab/qm/incidents/{$inc['O'][0]->id}/asu/state", []]];
    foreach ($inc as $v => [$i, $t, $d, $e]) {
        $base = "/firetab/incidents/{$i->id}/asu";
        $requests += [
            "state:$v" => ['GET', "$base/state", []],
            "create:$v" => ['POST', "$base/trupps", $trupp],
            "update:$v" => ['PUT', "$base/trupps/{$t->id}", ['name' => 'AT 1 neu'] + $trupp],
            "event:$v" => ['POST', "$base/events", ['trupp_id' => $t->id, 'type' => 'druck', 'bar' => 250, 'client_uuid' => (string) Str::uuid()]],
            "void:$v" => ['POST', "$base/events/{$e->id}/void", []],
            "delete:$v" => ['DELETE', "$base/trupps/{$d->id}", []],
        ];
    }

    $bad = [];
    foreach ($requests as $key => [$method, $url, $data]) {
        $expected = P8B_EXPECTED[$actor][$key] ?? P8B_EXPECTED[$actor][strtok($key, ':')] ?? P8B_EXPECTED[$actor]['*'];
        app('auth')->forgetGuards();
        $http = $user === null ? test() : test()->actingAs($user);
        $response = $http->withSession($session)->json($method, $url, $data);
        $got = $response->isRedirect(url('/firetab/login')) ? 'crew' : $response->getStatusCode();
        if ($got !== $expected) {
            $bad[] = "{$key}: {$method} {$url} expected {$expected}, got {$got}";
        }
    }
    app('auth')->forgetGuards();
    $sync = test()->call('POST', '/api/asu/sync', [], [], [], ['HTTP_X-API-Key' => app(ApiKey::class)->current(), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        json_encode(['type' => 'asu_protocol', 'data' => ['missionNumber' => 'E-Z', 'supervisor' => 'Max', 'trupps' => []]]));
    if ($sync->getStatusCode() !== P8B_EXPECTED[$actor]['sync']) {
        $bad[] = "sync: expected 200, got {$sync->getStatusCode()}";
    }
    expect($bad)->toBe([]);

    $writes = in_array($actor, ['crew', 'super-admin'], true);
    foreach ($inc as $v => [$i, $t, $d, $e]) {
        $w = $writes && $v === 'O';
        expect(AsuTrupp::where('fire_incident_id', $i->id)->pluck('name')->sort()->values()->all())->toBe($w ? ['AT 1 neu', 'AT 2'] : ['AT 1', 'Leer'])
            ->and(AsuEvent::where('fire_incident_id', $i->id)->count())->toBe($w ? 2 : 1)
            ->and($e->fresh()->voided_at !== null)->toBe($w);
    }
    expect(AsuProtocol::where('fire_incident_id', $inc['Z'][0]->id)->count())->toBe(1);
})->with(array_keys(P8B_EXPECTED));
