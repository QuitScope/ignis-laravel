<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Vehicles\TacticalSymbol;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Modules\FireTab\Events\MapChanged;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentLog;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Models\FireMapMarker;
use Modules\FireTab\Models\FireMapZone;

uses(RefreshDatabase::class);

function map_crew(object $t, ?Vehicle $vehicle = null, ?User $user = null): User
{
    $user ??= User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $vehicle ??= Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $t->actingAs($user)->withSession(['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]);

    return $user;
}

function map_incident(?Vehicle $vehicle, array $over = []): FireIncident
{
    $i = (new FireIncident)->forceFill(['location' => 'Hauptstr. 1', 'keyword' => 'B3', 'started_at' => now(), ...$over]);
    $i->save();
    if ($vehicle !== null) {
        (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $i->id, 'vehicle_id' => $vehicle->id, 'name' => $vehicle->name])->save();
    }

    return $i->fresh();
}

function map_qm(): User
{
    test()->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'Q'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['fire.incident.qm']);

    return User::factory()->withRole($role)->create(['personnel_id' => Personnel::factory()->create()->id]);
}

function map_marker(array $over = []): array
{
    return ['marker_type' => 'Einsatzleiter', 'pos_x' => 12.345, 'pos_y' => 50, 'grundzeichen' => 'person', 'organisation' => 'fuehrung', 'text' => 'EL', ...$over];
}

function map_zone(array $over = []): array
{
    return ['name' => 'Sperrzone', 'color' => '#dc3545', 'points' => [['x' => 1, 'y' => 1], ['x' => 10, 'y' => 1], ['x' => 5.555, 'y' => 9]], ...$over];
}

it('creates, moves and deletes a marker with attribution from the session, never from input', function () {
    Event::fake([MapChanged::class]);
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $user = map_crew($this, $vehicle);
    $i = map_incident($vehicle);
    $other = Vehicle::factory()->create();

    $id = $this->postJson("/firetab/incidents/{$i->id}/map/markers", map_marker(['vehicle_id' => $other->id, 'personnel_id' => 999, 'created_by_user_id' => 999, 'marker_type' => 'Unbekannt']))
        ->assertCreated()->json('id');
    $m = FireMapMarker::findOrFail($id);
    expect($m->only(['created_by_user_id', 'personnel_id', 'vehicle_id', 'marker_type', 'pos_x']))
        ->toBe(['created_by_user_id' => $user->id, 'personnel_id' => $user->personnel_id, 'vehicle_id' => $vehicle->id, 'marker_type' => 'custom', 'pos_x' => 12.35]);

    $this->patchJson("/firetab/incidents/{$i->id}/map/markers/$id", ['pos_x' => 70, 'pos_y' => 20, 'text' => 'changed'])->assertOk();
    expect($m->fresh()->only(['pos_x', 'pos_y', 'text']))->toBe(['pos_x' => 70.0, 'pos_y' => 20.0, 'text' => 'EL']);

    $this->getJson("/firetab/incidents/{$i->id}/map")->assertOk();
    $foreign = map_incident($vehicle);
    $this->deleteJson("/firetab/incidents/{$foreign->id}/map/markers/$id")->assertNotFound();

    $this->deleteJson("/firetab/incidents/{$i->id}/map/markers/$id")->assertOk();
    expect(FireMapMarker::count())->toBe(0)
        ->and(FireIncidentLog::where('fire_incident_id', $i->id)->pluck('action')->all())->toBe(['marker_created', 'marker_moved', 'marker_deleted']);
    Event::assertDispatchedTimes(MapChanged::class, 3);
})->group('mariadb');

it('creates and deletes a zone with rounded points and session attribution', function () {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $user = map_crew($this, $vehicle);
    $i = map_incident($vehicle);

    $id = $this->postJson("/firetab/incidents/{$i->id}/map/zones", map_zone())->assertCreated()->json('id');
    $z = FireMapZone::findOrFail($id);
    expect($z->points[2])->toEqual(['x' => 5.56, 'y' => 9])
        ->and($z->only(['created_by_user_id', 'personnel_id', 'vehicle_id']))->toBe(['created_by_user_id' => $user->id, 'personnel_id' => $user->personnel_id, 'vehicle_id' => $vehicle->id]);

    $this->get("/firetab/incidents/{$i->id}/map")->assertInertia(fn (AssertableInertia $p) => $p->component('FireTab/Map')
        ->where('zones.0.name', 'Sperrzone')->where('zones.0.personnel', $user->personnel->full_name)->where('can.write', true)->where('zones.0.can_delete', true)->where('qm', false));

    $this->deleteJson("/firetab/incidents/{$i->id}/map/zones/$id")->assertOk();
    expect(FireMapZone::count())->toBe(0)
        ->and(FireIncidentLog::where('fire_incident_id', $i->id)->pluck('action')->all())->toBe(['zone_created', 'zone_deleted']);
});

it('lets only the assigned crew of an open incident write; super-admin with crew session too is refused', function () {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    map_crew($this, $vehicle);
    $this->postJson('/firetab/incidents/'.map_incident(null)->id.'/map/markers', map_marker())->assertForbidden();
    $this->postJson('/firetab/incidents/'.map_incident($vehicle, ['finalized_at' => now()])->id.'/map/zones', map_zone())->assertForbidden();

    $i = map_incident($vehicle);
    (new FireMapMarker)->forceFill(['fire_incident_id' => $i->id, 'marker_type' => 'custom', 'pos_x' => 1, 'pos_y' => 2])->save();
    map_crew($this, null, User::factory()->superAdmin()->create(['personnel_id' => Personnel::factory()->create()->id]));
    $this->postJson("/firetab/incidents/{$i->id}/map/markers", map_marker())->assertForbidden();
    $this->deleteJson("/firetab/incidents/{$i->id}/map/markers/".FireMapMarker::value('id'))->assertForbidden();
    expect(FireMapMarker::count() + FireMapZone::count())->toBe(1);
});

it('lets QM read through the crew-free route; guests and crew-less users get nothing', function () {
    $i = map_incident(null);
    (new FireMapMarker)->forceFill(['fire_incident_id' => $i->id, 'marker_type' => 'custom', 'pos_x' => 1, 'pos_y' => 2])->save();

    $this->get("/firetab/qm/incidents/{$i->id}/map")->assertRedirect();
    $this->actingAs(User::factory()->create())->get("/firetab/qm/incidents/{$i->id}/map")->assertForbidden();
    $this->actingAs(map_qm())->get("/firetab/qm/incidents/{$i->id}/map")
        ->assertInertia(fn (AssertableInertia $p) => $p->component('FireTab/Map')->has('markers', 1)->where('can.write', false)->where('qm', true)->where('markers.0.can_delete', false));
});

it('deletes own items only (same account or same personnel record); QM deletes any', function () {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $i = map_incident($vehicle);
    $a = map_crew($this, $vehicle);
    $mine = $this->postJson("/firetab/incidents/{$i->id}/map/markers", map_marker())->json('id');
    $zone = $this->postJson("/firetab/incidents/{$i->id}/map/zones", map_zone())->json('id');

    map_crew($this, $vehicle);
    $this->deleteJson("/firetab/incidents/{$i->id}/map/markers/$mine")->assertForbidden();
    $this->deleteJson("/firetab/incidents/{$i->id}/map/zones/$zone")->assertForbidden();

    $person = $a->personnel_id;
    $a->forceFill(['personnel_id' => null])->save();
    map_crew($this, $vehicle, User::factory()->create(['personnel_id' => $person]));
    $this->deleteJson("/firetab/incidents/{$i->id}/map/zones/$zone")->assertOk();

    map_crew($this, null, map_qm());
    $this->deleteJson("/firetab/incidents/{$i->id}/map/markers/$mine")->assertOk();
    expect(FireMapMarker::count() + FireMapZone::count())->toBe(0);
});

it('rejects invalid input', function (string $kind, array $payload, string $error) {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    map_crew($this, $vehicle);
    $i = map_incident($vehicle);
    $this->postJson("/firetab/incidents/{$i->id}/map/$kind", $kind === 'markers' ? map_marker($payload) : map_zone($payload))
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'unknown grundzeichen' => ['markers', ['grundzeichen' => 'gibt-es-nicht'], 'grundzeichen'],
    'unknown symbol' => ['markers', ['symbol' => '<svg>'], 'symbol'],
    'position out of range' => ['markers', ['pos_x' => 100.01], 'pos_x'],
    'overlong text' => ['markers', ['text' => str_repeat('a', 101)], 'text'],
    'overlong description' => ['markers', ['description' => str_repeat('a', 501)], 'description'],
    'colour not in list' => ['zones', ['color' => 'red;background:url(x)'], 'color'],
    'too few points' => ['zones', ['points' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]]], 'points'],
    'too many points' => ['zones', ['points' => array_fill(0, 101, ['x' => 1, 'y' => 1])], 'points'],
    'point out of range' => ['zones', ['points' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => -1], ['x' => 3, 'y' => 3]]], 'points.1.y'],
    'point with extra key' => ['zones', ['points' => [['x' => 1, 'y' => 1, 'z' => 1], ['x' => 2, 'y' => 2], ['x' => 3, 'y' => 3]]], 'points.0'],
]);

it('stores XSS payloads as plain text and only returns them JSON-encoded', function () {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    map_crew($this, $vehicle);
    $i = map_incident($vehicle);
    $xss = '"><script>alert(1)</script><img src=x onerror=alert(2)>';

    $this->postJson("/firetab/incidents/{$i->id}/map/markers", map_marker(['description' => $xss, 'name' => $xss, 'typ' => $xss, 'text' => $xss]))->assertCreated();
    $this->postJson("/firetab/incidents/{$i->id}/map/zones", map_zone(['name' => $xss, 'description' => $xss]))->assertCreated();
    expect(FireMapMarker::first()->description)->toBe($xss)->and(FireMapZone::first()->name)->toBe($xss);

    $this->get("/firetab/incidents/{$i->id}/map")->assertDontSee('<script>alert(1)', false)->assertDontSee('<img src=x', false)
        ->assertInertia(fn (AssertableInertia $p) => $p->component('FireTab/Map')
            ->where('markers.0.description', $xss)->where('markers.0.text', $xss)->where('zones.0.name', $xss));
});

it('places the Einsatzort marker idempotently and only for the assigned crew', function () {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    map_crew($this, $vehicle);
    $i = map_incident($vehicle, ['location_x' => 0, 'location_y' => 0]);

    $first = $this->putJson("/firetab/incidents/{$i->id}/map/location")->assertOk()->assertJson(['pos_x' => 45.8, 'pos_y' => 67.38])->json('id');
    $i->forceFill(['location_x' => 1232.9, 'location_y' => 0])->save();
    expect($this->putJson("/firetab/incidents/{$i->id}/map/location")->assertOk()->json('id'))->toBe($first)
        ->and(FireMapMarker::count())->toBe(1)
        ->and(FireMapMarker::first()->pos_x)->toBe(55.8)
        ->and(TacticalSymbol::validate(FireMapMarker::first()->only(TacticalSymbol::KEYS)))->toBe(['grundzeichen' => 'ohne', 'symbol' => 'vollbrand']);

    $this->putJson('/firetab/incidents/'.map_incident(null, ['location_x' => 0, 'location_y' => 0])->id.'/map/location')->assertForbidden();
    $this->putJson('/firetab/incidents/'.map_incident($vehicle)->id.'/map/location')->assertUnprocessable();
})->group('mariadb');
