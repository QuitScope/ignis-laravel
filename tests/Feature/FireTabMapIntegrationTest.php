<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentLog;
use Modules\FireTab\Models\FireIncidentVehicle;

uses(RefreshDatabase::class);

it('runs the whole map flow: marker, move, zone, location, QM read, finalize makes it read-only', function () {
    $this->seed(RoleSeeder::class);
    $crew = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $own = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE, 'name' => 'HLF 1']);
    $incident = (new FireIncident)->forceFill(['number' => 'E-300', 'location' => 'Hauptstr. 1', 'keyword' => 'B3', 'started_at' => now(), 'location_x' => 100, 'location_y' => -200]);
    $incident->save();
    (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $incident->id, 'vehicle_id' => $own->id, 'name' => $own->name])->save();
    $session = ['crew.firetab' => ['user_id' => $crew->id, 'vehicle_id' => $own->id, 'personnel_id' => $crew->personnel_id, 'since' => time()]];
    $this->actingAs($crew)->withSession($session);
    $url = "/firetab/incidents/{$incident->id}/map";

    $marker = $this->postJson("$url/markers", ['marker_type' => 'Einsatzleiter', 'pos_x' => 40, 'pos_y' => 40, 'grundzeichen' => 'person', 'text' => 'EL'])->assertCreated()->json('id');
    $this->patchJson("$url/markers/$marker", ['pos_x' => 41.5, 'pos_y' => 39])->assertOk();
    $this->postJson("$url/zones", ['name' => 'Gefahrenbereich', 'color' => '#ffc107', 'points' => [['x' => 1, 'y' => 1], ['x' => 9, 'y' => 1], ['x' => 5, 'y' => 9]]])->assertCreated();
    $loc = $this->putJson("$url/location")->assertOk()->json();
    $this->putJson("$url/location")->assertOk()->assertJson(['id' => $loc['id']]);

    $qm = User::factory()->withRole(tap(Role::create(['name' => 'QM', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']))->syncPermissions(['fire.incident.qm']))->create();
    app('auth')->forgetGuards();
    $this->actingAs($qm)->withSession([])->get("/firetab/qm/incidents/{$incident->id}/map")->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('FireTab/Map')->where('qm', true)->where('can.write', false)
            ->has('markers', 2)->where('markers.0.pos_x', 41.5)->where('markers.0.personnel', $crew->personnel->full_name)->where('markers.0.vehicle', 'HLF 1')
            ->where('markers.1.marker_type', 'Einsatzort')->has('zones', 1)->where('zones.0.name', 'Gefahrenbereich'));

    $incident->forceFill(['finalized_at' => now()])->save();
    app('auth')->forgetGuards();
    $this->actingAs($crew)->withSession($session);
    $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('can.write', false)->where('markers.0.can_delete', false));
    $this->patchJson("$url/markers/$marker", ['pos_x' => 1, 'pos_y' => 1])->assertForbidden();
    $this->deleteJson("$url/markers/$marker")->assertForbidden();

    expect(FireIncidentLog::where('fire_incident_id', $incident->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['marker_created', 'marker_moved', 'zone_created', 'marker_created', 'marker_moved']);
});
