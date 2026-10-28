<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Models\FireMapMarker;
use Modules\FireTab\Models\FireMapZone;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

const P8C_EXPECTED = [
    'guest' => ['*' => 401],
    'no-session' => ['*' => 'crew', 'qmmap' => 403],
    'crew' => ['*' => 403, 'map:O' => 200, 'map:Z' => 200, 'mark:O' => 201, 'move:O' => 200, 'delown:O' => 200, 'zone:O' => 201, 'delzone:O' => 200,
        'location:O' => 200, 'qmmap' => 200],
    'qm' => ['*' => 'crew', 'qmmap' => 200],
    'super-admin' => ['*' => 403, 'map:O' => 200, 'map:F' => 200, 'map:Z' => 200, 'mark:O' => 201, 'move:O' => 200, 'delown:O' => 200, 'zone:O' => 201,
        'delzone:O' => 200, 'location:O' => 200, 'qmmap' => 200],
];

/** @return array{0: FireIncident, 1: FireMapMarker, 2: FireMapMarker, 3: FireMapZone} */
function p8c_incident(Vehicle $vehicle, ?User $user, array $over = []): array
{
    $i = (new FireIncident)->forceFill(['location' => 'Hauptstr. 1', 'keyword' => 'B2', 'started_at' => now(), 'location_x' => 0, 'location_y' => 0, ...$over]);
    $i->save();
    (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $i->id, 'vehicle_id' => $vehicle->id, 'name' => $vehicle->name])->save();
    $item = fn ($model, array $data) => tap($model->forceFill(['fire_incident_id' => $i->id, ...$data]))->save();
    $own = $item(new FireMapMarker, ['marker_type' => 'Einsatzleiter', 'pos_x' => 10, 'pos_y' => 10, 'created_by_user_id' => $user?->id]);
    $foreign = $item(new FireMapMarker, ['marker_type' => 'Bereitstellungsraum', 'pos_x' => 20, 'pos_y' => 20, 'personnel_id' => Personnel::factory()->create()->id]);
    $zone = $item(new FireMapZone, ['name' => 'Alt', 'color' => '#dc3545', 'points' => [['x' => 1, 'y' => 1], ['x' => 9, 'y' => 1], ['x' => 5, 'y' => 9]], 'created_by_user_id' => $user?->id]);

    return [$i, $own, $foreign, $zone];
}

it('answers every map route with the exact status and leaves the right state', function (string $actor) {
    $mine = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $user = match ($actor) {
        'guest' => null,
        'no-session', 'crew' => User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]),
        'qm' => User::factory()->withRole(tap(Role::create(['name' => 'QM', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']))->syncPermissions(['fire.incident.qm']))
            ->create(['personnel_id' => Personnel::factory()->create()->id]),
        'super-admin' => User::factory()->superAdmin()->create(['personnel_id' => Personnel::factory()->create()->id]),
    };
    $inc = ['O' => p8c_incident($mine, $user), 'F' => p8c_incident(Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]), $user),
        'Z' => p8c_incident($mine, $user, ['finalized_at' => now()])];
    $session = in_array($actor, ['crew', 'super-admin'], true)
        ? ['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $mine->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]
        : [];

    $requests = ['qmmap' => ['GET', "/firetab/qm/incidents/{$inc['O'][0]->id}/map", []]];
    foreach ($inc as $v => [$i, $own, $foreign, $zone]) {
        $base = "/firetab/incidents/{$i->id}/map";
        $requests += [
            "map:$v" => ['GET', $base, []],
            "mark:$v" => ['POST', "$base/markers", ['marker_type' => 'Verletzte Person', 'pos_x' => 30, 'pos_y' => 30]],
            "move:$v" => ['PATCH', "$base/markers/{$foreign->id}", ['pos_x' => 70, 'pos_y' => 70]],
            "delown:$v" => ['DELETE', "$base/markers/{$own->id}", []],
            "delforeign:$v" => ['DELETE', "$base/markers/{$foreign->id}", []],
            "zone:$v" => ['POST', "$base/zones", ['name' => 'Neu', 'color' => '#0d6efd', 'points' => [['x' => 1, 'y' => 1], ['x' => 9, 'y' => 1], ['x' => 5, 'y' => 9]]]],
            "delzone:$v" => ['DELETE', "$base/zones/{$zone->id}", []],
            "location:$v" => ['PUT', "$base/location", []],
        ];
    }

    $bad = [];
    foreach ($requests as $key => [$method, $url, $data]) {
        $expected = P8C_EXPECTED[$actor][$key] ?? P8C_EXPECTED[$actor][strtok($key, ':')] ?? P8C_EXPECTED[$actor]['*'];
        app('auth')->forgetGuards();
        $http = $user === null ? test() : test()->actingAs($user);
        $response = $http->withSession($session)->json($method, $url, $data);
        $got = $response->isRedirect(url('/firetab/login')) ? 'crew' : $response->getStatusCode();
        if ($got !== $expected) {
            $bad[] = "{$key}: {$method} {$url} expected {$expected}, got {$got}";
        }
    }
    expect($bad)->toBe([]);

    $writes = in_array($actor, ['crew', 'super-admin'], true);
    foreach ($inc as $v => [$i, $own, $foreign]) {
        $w = $writes && $v === 'O';
        expect(FireMapMarker::where('fire_incident_id', $i->id)->orderBy('marker_type')->pluck('marker_type')->all())
            ->toBe($w ? ['Bereitstellungsraum', 'Einsatzort', 'Verletzte Person'] : ['Bereitstellungsraum', 'Einsatzleiter'])
            ->and($foreign->fresh()->pos_x)->toBe($w ? 70.0 : 20.0)
            ->and(FireMapZone::where('fire_incident_id', $i->id)->pluck('name')->all())->toBe($w ? ['Neu'] : ['Alt']);
    }
})->with(array_keys(P8C_EXPECTED));
