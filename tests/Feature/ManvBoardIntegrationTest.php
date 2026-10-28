<?php

use App\Models\Personnel;
use App\Models\Poi;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;

uses(RefreshDatabase::class);

function p7a_int_user(string $name, string ...$perms): User
{
    $role = Role::create(['name' => 'I'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);
    $file = Personnel::factory()->create(['full_name' => $name]);

    return User::factory()->withRole($role)->create(['personnel_id' => $file->id]);
}

it('runs Lage, resource, patient, triage and departure with a complete log', function () {
    $this->seed(RoleSeeder::class);
    $lna = Personnel::factory()->create(['full_name' => 'Dr. Notarzt']);
    $anna = p7a_int_user('Anna Einsatz', 'mci.manage');
    $berta = p7a_int_user('Berta Sichtung', 'mci.manage');
    $viewer = p7a_int_user('Viktor Leser', 'mci.view');
    $vehicle = Vehicle::factory()->create(['name' => 'RTW 83-1', 'service' => Vehicle::SERVICE_EMS]);
    $clinic = Poi::factory()->create(['type' => 'Krankenhaus', 'name' => 'Klinikum Nord']);

    $this->actingAs($anna)->post('/mci', ['incident_number' => 'MANV-1', 'location' => 'A3 km 12', 'reason' => 'Busunfall', 'lna_personnel_id' => $lna->id, 'lna_name' => 'Fälschung'])
        ->assertRedirect();
    $incident = ManvIncident::sole();
    expect($incident->lna_name)->toBe('Dr. Notarzt')->and($incident->created_by)->toBe($anna->id);

    $this->actingAs($anna)->post("/mci/{$incident->id}/resources", ['vehicle_id' => $vehicle->id])->assertRedirect()->assertSessionHasNoErrors();
    $resource = ManvResource::sole();
    expect($resource->label)->toBe('RTW 83-1')->and($resource->vehicle_id)->toBe($vehicle->id);

    $this->actingAs($berta)->post("/mci/{$incident->id}/patients", ['triage' => 'SK3', 'sex' => 'f', 'last_name' => 'Muster', 'injuries' => 'Prellung'])->assertRedirect();
    $patient = ManvPatient::sole();
    expect($patient->number)->toBe('MANV-001')->and($patient->created_by)->toBe($berta->id);

    $this->actingAs($berta)->patch("/mci/{$incident->id}/patients/{$patient->id}/triage", ['triage' => 'SK1'])->assertRedirect();
    $this->actingAs($berta)->put("/mci/{$incident->id}/patients/{$patient->id}", [
        'triage' => 'SK1', 'sex' => 'f', 'last_name' => 'Muster', 'injuries' => 'Prellung, Schock',
        'manv_resource_id' => $resource->id, 'destination_poi_id' => $clinic->id,
    ])->assertSessionHasNoErrors();
    $patient->refresh();
    expect($patient->triage->value)->toBe('SK1')->and($patient->manv_resource_id)->toBe($resource->id)->and($patient->destination)->toBe('Klinikum Nord');

    $board = $this->actingAs($viewer)->get("/mci/{$incident->id}");
    $board->assertInertia(fn ($p) => $p->where('patients.0.number', 'MANV-001')->where('patients.0.can_depart', true)->where('stats.waiting', 1)->where('can.write', false));
    expect($board->getContent())->not->toContain('Muster')->not->toContain('Prellung');

    $this->actingAs($viewer)->post("/mci/{$incident->id}/patients/{$patient->id}/departure")->assertForbidden();
    $this->actingAs($anna)->post("/mci/{$incident->id}/patients/{$patient->id}/departure")->assertSessionHasNoErrors();
    $this->actingAs($anna)->postJson("/mci/{$incident->id}/patients/{$patient->id}/departure")->assertStatus(422);
    expect($patient->refresh()->departed_at)->not->toBeNull()->and($resource->refresh()->departed_at)->not->toBeNull();
    $this->actingAs($viewer)->get("/mci/{$incident->id}")->assertInertia(fn ($p) => $p->has('patients', 0)->where('stats.departed', 1));

    $actions = ManvLogEntry::orderBy('id')->get(['action', 'user_id', 'user_name'])->map(fn ($e) => [$e->action, $e->user_name, $e->user_id])->all();
    expect($actions)->toBe([
        ['lage_erstellt', 'Anna Einsatz', $anna->id],
        ['ressource_erstellt', 'Anna Einsatz', $anna->id],
        ['patient_erstellt', 'Berta Sichtung', $berta->id],
        ['sichtung_geaendert', 'Berta Sichtung', $berta->id],
        ['patient_aktualisiert', 'Berta Sichtung', $berta->id],
        ['patient_abfahrt', 'Anna Einsatz', $anna->id],
        ['ressource_abgefahren', 'Anna Einsatz', $anna->id],
    ]);
    expect(ManvLogEntry::whereNull('user_id')->orWhere('user_name', 'System')->count())->toBe(0);
    $this->actingAs($viewer)->get("/mci/{$incident->id}/log")->assertInertia(fn ($p) => $p->has('entries.data', 7)->where('entries.data.0.action', 'ressource_abgefahren'));

    $this->actingAs($anna)->put("/mci/{$incident->id}", ['incident_number' => 'MANV-1', 'location' => 'A3 km 12', 'status' => 'closed'])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($anna)->post("/mci/{$incident->id}/patients", ['triage' => 'SK2', 'sex' => 'm'])->assertForbidden();
    expect(ManvPatient::count())->toBe(1);
    $this->actingAs($anna)->put("/mci/{$incident->id}", ['status' => 'active'])->assertRedirect();
    $this->actingAs($anna)->post("/mci/{$incident->id}/patients", ['triage' => 'SK2', 'sex' => 'm'])->assertRedirect();
    expect(ManvPatient::pluck('number')->all())->toBe(['MANV-001', 'MANV-002']);
});
