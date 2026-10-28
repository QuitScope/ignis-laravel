<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentLog;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Models\FireStatusQueue;

uses(RefreshDatabase::class);

function st_crew(object $t, Vehicle $vehicle): void
{
    $user = User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $t->actingAs($user)->withSession(['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]);
}

function st_incident(Vehicle $vehicle, array $over = []): FireIncident
{
    $i = (new FireIncident)->forceFill(['number' => 'E-'.uniqid(), 'started_at' => now(), ...$over]);
    $i->save();
    (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $i->id, 'vehicle_id' => $vehicle->id, 'name' => $vehicle->name])->save();

    return $i;
}

it('sends a status without an active incident: queue row without number, vehicle status, no log', function () {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE, 'name' => 'HLF 1']);
    st_incident($vehicle, ['finalized_at' => now()]);
    st_crew($this, $vehicle);

    $this->get('/firetab/status')->assertInertia(fn (AssertableInertia $p) => $p->component('FireTab/Status')->where('incident', null)->has('buttons', 7));
    $this->post('/firetab/status', ['status' => '2'])->assertRedirect();

    $row = FireStatusQueue::sole();
    expect($row->incident_number)->toBeNull()->and($row->status)->toBe('2')->and($row->vehicle_name)->toBe('HLF 1')
        ->and($vehicle->fresh()->fms_status)->toBe('2')->and($vehicle->fresh()->fms_status_source)->toBe('incident')
        ->and(FireIncidentLog::count())->toBe(0);

    $this->post('/firetab/status', ['status' => '7'])->assertSessionHasErrors('status');
    expect(FireStatusQueue::count())->toBe(1);
});

it('sends a status with the newest open incident: incident vehicle status, log, queue number', function () {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    st_incident($vehicle, ['number' => 'OLD', 'started_at' => now()->subHour()]);
    $active = st_incident($vehicle, ['number' => 'NEW']);
    st_incident($vehicle, ['number' => 'ARCH', 'started_at' => now()->addMinute(), 'archived_at' => now()]);
    st_crew($this, $vehicle);

    $this->post('/firetab/status', ['status' => '3'])->assertRedirect();

    $link = FireIncidentVehicle::where('fire_incident_id', $active->id)->first();
    expect(FireStatusQueue::sole()->incident_number)->toBe('NEW')
        ->and($link->getAttribute('fms_status'))->toBe('3')->and($link->fms_status_at)->not->toBeNull()
        ->and(FireIncidentLog::where('fire_incident_id', $active->id)->value('action'))->toBe('fms_status')
        ->and(FireIncidentLog::count())->toBe(1);
});

it('throttles the status button to 12 per minute per vehicle', function () {
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    st_crew($this, $vehicle);
    foreach (range(1, 12) as $_) {
        $this->post('/firetab/status', ['status' => '1'])->assertRedirect();
    }
    $this->post('/firetab/status', ['status' => '1'])->assertTooManyRequests();

    st_crew($this, $vehicle);
    $this->post('/firetab/status', ['status' => '1'])->assertTooManyRequests();
    expect(FireStatusQueue::count())->toBe(12);
});
