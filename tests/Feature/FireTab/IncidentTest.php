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
use Modules\FireTab\Models\FireSitrep;

uses(RefreshDatabase::class)->group('mariadb');

function ft_crew(object $t, ?Vehicle $vehicle = null, ?User $user = null): array
{
    $user ??= User::factory()->create(['personnel_id' => Personnel::factory()->create()->id]);
    $vehicle ??= Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    $t->actingAs($user)->withSession(['crew.firetab' => ['user_id' => $user->id, 'vehicle_id' => $vehicle->id, 'personnel_id' => $user->personnel_id, 'since' => time()]]);

    return [$user, $vehicle];
}

function ft_assigned(Vehicle $vehicle, array $over = []): FireIncident
{
    $i = (new FireIncident)->forceFill(['location' => 'Hauptstr. 1', 'keyword' => 'B2', 'started_at' => now(), ...$over]);
    $i->save();
    (new FireIncidentVehicle)->forceFill(['fire_incident_id' => $i->id, 'vehicle_id' => $vehicle->id, 'name' => $vehicle->name, 'radio_name' => $vehicle->name])->save();

    return $i->fresh();
}

function ft_form(array $over = []): array
{
    return ['location' => 'Hauptstr. 1', 'keyword' => 'B2', 'started_date' => '2026-07-01', 'started_time' => '14:30', 'leader_personnel_id' => Personnel::factory()->create()->id, ...$over];
}

it('creates an incident: UTC start (summer and winter), own vehicle attached, leader, log, unique number', function () {
    config(['ignis.timezone' => 'Europe/Berlin']);
    [, $vehicle] = ft_crew($this);
    $leader = ft_form()['leader_personnel_id'];

    $this->post('/firetab/incidents', ft_form(['number' => 'E-1', 'leader_personnel_id' => $leader]))->assertRedirect();
    $this->post('/firetab/incidents', ft_form(['started_date' => '2026-01-15', 'started_time' => '08:00', 'leader_personnel_id' => $leader]))->assertRedirect();

    $summer = FireIncident::where('number', 'E-1')->first();
    expect($summer->started_at->format('Y-m-d H:i'))->toBe('2026-07-01 12:30')
        ->and($summer->leader_personnel_id)->toBe($leader)
        ->and($summer->vehicles()->pluck('vehicle_id')->all())->toBe([$vehicle->id])
        ->and(FireIncidentLog::where('fire_incident_id', $summer->id)->value('action'))->toBe('created')
        ->and(FireIncident::whereNull('number')->first()->started_at->format('H:i'))->toBe('07:00');

    $this->post('/firetab/incidents', ft_form(['number' => 'E-1']))->assertSessionHasErrors('number');
    $this->post('/firetab/incidents', ['number' => 'E-2'])->assertSessionHasErrors(['location', 'keyword', 'started_date', 'started_time', 'leader_personnel_id']);
    expect(FireIncident::count())->toBe(2);
});

it('lists only open incidents of the crew vehicle with counts and shows tabs', function () {
    [, $vehicle] = ft_crew($this);
    $mine = ft_assigned($vehicle);
    ft_assigned($vehicle, ['finalized_at' => now()]);
    ft_assigned(Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]));

    $this->get('/firetab/list')->assertInertia(fn (AssertableInertia $p) => $p->component('FireTab/List')->has('incidents', 1)->where('incidents.0.id', $mine->id)->where('incidents.0.vehicles_count', 1));
    foreach (['stammdaten', 'abschluss', 'log'] as $tab) {
        $this->get("/firetab/incidents/{$mine->id}?tab=$tab")->assertInertia(fn (AssertableInertia $p) => $p->component('FireTab/Show')->where('tab', $tab)->where('can.write', true));
    }
});

it('applies the policy: assigned crew writes, other crew gets 403, QM reads but does not write', function () {
    [, $vehicle] = ft_crew($this);
    $incident = ft_assigned($vehicle);
    $url = "/firetab/incidents/{$incident->id}";

    $this->put($url, ft_form(['location' => 'Neu']))->assertRedirect();
    $this->put("$url/report", ['notes' => 'Bericht'])->assertRedirect();
    expect($incident->fresh())->location->toBe('Neu')->notes->toBe('Bericht');

    ft_crew($this);
    $this->get($url)->assertForbidden();
    $this->put($url, ft_form())->assertForbidden();
    $this->post("$url/sitreps", [])->assertForbidden();

    $this->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'Q'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['fire.incident.qm']);
    $qm = User::factory()->withRole($role)->create(['personnel_id' => Personnel::factory()->create()->id]);
    ft_crew($this, null, $qm);
    $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('can.write', false));
    $this->put($url, ft_form())->assertForbidden();
    expect($incident->fresh()->location)->toBe('Neu');
});

it('rejects writes on finalized and archived incidents', function () {
    [, $vehicle] = ft_crew($this);
    foreach (['finalized_at', 'archived_at'] as $col) {
        $i = ft_assigned($vehicle, [$col => now()]);
        $this->put("/firetab/incidents/{$i->id}", ft_form())->assertForbidden();
        $this->put("/firetab/incidents/{$i->id}/report", ['notes' => 'x'])->assertForbidden();
        $this->post("/firetab/incidents/{$i->id}/vehicles", ['radio_name' => 'Florian'])->assertForbidden();
        $this->post("/firetab/incidents/{$i->id}/sitreps", [])->assertForbidden();
    }
});

it('scopes vehicles to the incident, adds system and external vehicles once, keeps the own vehicle', function () {
    [, $own] = ft_crew($this);
    $incident = ft_assigned($own);
    $other = ft_assigned(Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]));
    $foreign = $other->vehicles()->first();
    $extra = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE, 'name' => 'Zugfahrzeug-X']);
    $base = "/firetab/incidents/{$incident->id}/vehicles";

    $this->post($base, ['vehicle_id' => $extra->id])->assertRedirect();
    $this->post($base, ['vehicle_id' => $extra->id])->assertSessionHasErrors('vehicle_id');
    $this->post($base, ['radio_name' => 'Florian Nachbar'])->assertRedirect();
    $this->post($base, [])->assertSessionHasErrors('radio_name');
    expect($incident->vehicles()->count())->toBe(3);
    $this->getJson('/firetab/vehicles/search?q=Zugfahrzeug')->assertJsonCount(1)->assertJsonPath('0.id', $extra->id);

    $this->delete("$base/{$foreign->id}")->assertNotFound();
    $this->delete("$base/{$incident->vehicles()->where('vehicle_id', $own->id)->value('id')}")->assertSessionHasErrors('vehicle');
    $this->delete("$base/{$incident->vehicles()->where('vehicle_id', $extra->id)->value('id')}")->assertRedirect();
    expect($incident->vehicles()->count())->toBe(2);
});

it('stores sitreps in UTC with the reporting vehicle from this incident only, and escapes text', function () {
    config(['ignis.timezone' => 'Europe/Berlin']);
    [, $own] = ft_crew($this);
    $incident = ft_assigned($own);
    $foreign = ft_assigned(Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]))->vehicles()->first();
    $mine = $incident->vehicles()->first();
    $url = "/firetab/incidents/{$incident->id}/sitreps";
    $xss = '<script>alert(1)</script>';
    $body = ['date' => '2026-07-01', 'time' => '10:15', 'text' => $xss, 'incident_vehicle_id' => $mine->id];

    $this->post($url, [...$body, 'incident_vehicle_id' => $foreign->id])->assertSessionHasErrors('incident_vehicle_id');
    $this->post($url, [...$body, 'text' => str_repeat('a', 2001)])->assertSessionHasErrors('text');
    $this->post($url, $body)->assertRedirect();

    $s = FireSitrep::first();
    expect(FireSitrep::count())->toBe(1)
        ->and($s->reported_at->format('Y-m-d H:i'))->toBe('2026-07-01 08:15')
        ->and($s->reporter_name)->toBe($own->name)
        ->and($s->source->value)->toBe('local')
        ->and($s->synced_at)->toBeNull();

    expect(file_get_contents(base_path('resources/js/components/firetab/Lagemeldungen.vue')))->not->toContain('v-html');
    $this->get("/firetab/incidents/{$incident->id}?tab=lagemeldungen")->assertInertia(fn (AssertableInertia $p) => $p->where('sitreps.0.text', $xss));
    $this->get("/firetab/incidents/{$incident->id}?tab=lagemeldungen")->assertDontSee($xss, false);
});

it('never lets a super-admin write a finalized or foreign incident, but lets them read', function () {
    $admin = User::factory()->superAdmin()->create(['personnel_id' => Personnel::factory()->create()->id]);
    [, $own] = ft_crew($this, null, $admin);
    $other = Vehicle::factory()->create(['service' => Vehicle::SERVICE_FIRE]);
    foreach ([ft_assigned($own, ['finalized_at' => now()]), ft_assigned($other)] as $i) {
        $url = "/firetab/incidents/{$i->id}";
        $this->get($url)->assertOk();
        $this->put($url, ft_form())->assertForbidden();
        $this->put("$url/report", ['notes' => 'x'])->assertForbidden();
        $this->post("$url/vehicles", ['radio_name' => 'F'])->assertForbidden();
        $this->post("$url/sitreps", [])->assertForbidden();
    }
});

it('turns a lost race on the unique number into a validation error on update', function () {
    [, $own] = ft_crew($this);
    $i = ft_assigned($own);
    $armed = true;
    DB::listen(function ($q) use (&$armed) {
        if ($armed && str_starts_with($q->sql, 'select') && str_contains($q->sql, 'fire_incidents') && str_contains(str_replace('`', '"', $q->sql), '"number"')) {
            $armed = false;
            DB::table('fire_incidents')->insert(['number' => 'RACE', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->put("/firetab/incidents/{$i->id}", ft_form(['number' => 'RACE']))->assertSessionHasErrors('number');
    expect($i->fresh()->number)->toBeNull();
});
