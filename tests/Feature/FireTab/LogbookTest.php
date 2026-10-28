<?php

use App\Models\Personnel;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Logbook\Models\LogbookEntry;

uses(RefreshDatabase::class);

function lg_crew(object $t, ?User $user = null, ?Vehicle $vehicle = null): array
{
    $vehicle ??= Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $user ??= User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $t->actingAs($user)->withSession(['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]);

    return [$user, $vehicle];
}

function lg_body(string $key, array $over = []): array
{
    return $over + ['key' => $key, 'trip_date' => '2026-10-20', 'departed_at' => '08:30', 'arrived_at' => '09:00', 'distance_km' => 4.5, 'trip_type' => 'einsatz', 'reason' => 'x'];
}

it('creates with the session vehicle and driver, ignores form values, and a double submit makes one row', function () {
    [$user, $vehicle] = lg_crew($this);
    $other = Vehicle::factory()->create();
    $key = $this->get('/firetab/logbook')->viewData('page')['props']['formKey'];

    $body = lg_body($key, ['vehicle_id' => $other->id, 'driver_name' => 'Fremder', 'source' => 'admin']);
    $this->post('/firetab/logbook', $body)->assertRedirect('/firetab/logbook');
    $this->post('/firetab/logbook', $body)->assertRedirect('/firetab/logbook');

    $e = LogbookEntry::sole();
    expect($e->vehicle_id)->toBe($vehicle->id)->and($e->source)->toBe('firetab')->and($e->created_by)->toBe($user->id)
        ->and($e->driver_name)->toBe(trim($user->personnel->displayName()));
});

it('answers 404 with the module logbook off', function () {
    lg_crew($this);
    app(Settings::class)->set('modules.logbook.enabled', false);

    $this->get('/firetab/logbook')->assertNotFound();
    $this->post('/firetab/logbook', lg_body('x'))->assertNotFound();
    $this->get('/firetab/list')->assertInertia(fn ($p) => $p->where('logbook', false));
});

it('edits only own firetab entries of the same driver and never deletes', function () {
    [$user, $vehicle] = lg_crew($this);
    $key = $this->get('/firetab/logbook')->viewData('page')['props']['formKey'];
    $this->post('/firetab/logbook', lg_body($key));
    $own = LogbookEntry::sole();

    $this->put("/firetab/logbook/{$own->id}", lg_body('', ['distance_km' => 9]))->assertRedirect('/firetab/logbook');
    expect($own->fresh()->distance_km)->toBe(9.0);

    $admin = (new LogbookEntry)->forceFill(['vehicle_id' => $vehicle->id, 'vehicle_identifier' => 'X', 'trip_date' => '2026-10-20', 'departed_at' => '08:00', 'distance_km' => 1,
        'trip_type' => 'einsatz', 'driver_name' => $own->driver_name, 'source' => 'admin', 'created_by' => $user->id]);
    $admin->save();
    $this->put("/firetab/logbook/{$admin->id}", lg_body('', ['distance_km' => 9]))->assertForbidden();

    lg_crew($this, null, $vehicle);
    $this->put("/firetab/logbook/{$own->id}", lg_body('', ['distance_km' => 7]))->assertForbidden();
    $this->delete("/firetab/logbook/{$own->id}")->assertStatus(405);
    expect($own->fresh()->distance_km)->toBe(9.0);
});

it('does not 500 on a vehicle search with an array q', function () {
    lg_crew($this);
    $this->get('/firetab/login?q[]=x')->assertOk();
});
