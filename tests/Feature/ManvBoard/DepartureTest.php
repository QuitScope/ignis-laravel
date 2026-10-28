<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;
use Modules\ManvBoard\Support\TransportRules;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mvd_user(string ...$perms): User
{
    $role = Role::create(['name' => 'D'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function mvd_incident(array $over = []): ManvIncident
{
    $i = (new ManvIncident)->forceFill($over + ['incident_number' => 'L-'.uniqid(), 'location' => 'Halle', 'started_at' => now()]);
    $i->save();

    return $i;
}

function mvd_ready(?ManvIncident $i = null, array $over = [], ?int $service = Vehicle::SERVICE_EMS, ?ManvResource $resource = null): ManvPatient
{
    $i ??= mvd_incident();
    if ($resource === null) {
        $resource = (new ManvResource)->forceFill(['manv_incident_id' => $i->id, 'type' => 'vehicle', 'label' => 'RTW '.uniqid(),
            'vehicle_id' => $service === null ? null : Vehicle::factory()->create(['service' => $service])->id]);
        $resource->save();
    }
    $p = (new ManvPatient)->forceFill($over + ['manv_incident_id' => $i->id, 'number' => 'MANV-'.uniqid(), 'triage' => 'SK1',
        'manv_resource_id' => $resource->id, 'destination' => 'Klinikum Nord']);
    $p->save();

    return $p;
}

it('allows transport only for SK1-3 with an EMS vehicle and a destination', function () {
    foreach ([
        'SK1 RD mit NA' => [[], Vehicle::SERVICE_EMS_PHYSICIAN, true],
        'SK2 RD' => [['triage' => 'SK2'], Vehicle::SERVICE_EMS, true],
        'SK3 RD' => [['triage' => 'SK3'], Vehicle::SERVICE_EMS, true],
        'SK4' => [['triage' => 'SK4'], Vehicle::SERVICE_EMS, false],
        'SK5' => [['triage' => 'SK5'], Vehicle::SERVICE_EMS, false],
        'SK6' => [['triage' => 'SK6'], Vehicle::SERVICE_EMS, false],
        'ungesichtet' => [['triage' => null], Vehicle::SERVICE_EMS, false],
        'Feuerwehr (service 3)' => [[], Vehicle::SERVICE_FIRE, false],
        'Sonstiges (service 0)' => [[], Vehicle::SERVICE_OTHER, false],
        'Ressource ohne Bestandsfahrzeug' => [[], null, false],
        'ohne Fahrzeug' => [['manv_resource_id' => null], Vehicle::SERVICE_EMS, false],
        'ohne Ziel' => [['destination' => null], Vehicle::SERVICE_EMS, false],
        'Kein Transport' => [['destination' => 'Kein Transport'], Vehicle::SERVICE_EMS, false],
        'schon abgefahren' => [['departed_at' => now()], Vehicle::SERVICE_EMS, false],
    ] as [$over, $service, $ok]) {
        $p = mvd_ready(over: $over, service: $service);
        expect(TransportRules::canDepart($p->fresh()))->toBe($ok);
    }
});

it('departs the patient, keeps the resource and marks it departed, with log entries', function () {
    $p = mvd_ready();
    $m = mvd_user('mci.manage');

    $this->actingAs($m)->post("/mci/{$p->manv_incident_id}/patients/{$p->id}/departure")->assertRedirect()->assertSessionHasNoErrors();
    $p->refresh();
    $r = ManvResource::find($p->manv_resource_id);
    expect($p->departed_at)->not->toBeNull()->and($p->updated_by)->toBe($m->id)
        ->and($r)->not->toBeNull()->and($r->departed_at)->not->toBeNull();
    expect(ManvLogEntry::where('action', 'patient_abfahrt')->where('subject_id', $p->id)->count())->toBe(1)
        ->and(ManvLogEntry::where('action', 'ressource_abgefahren')->where('subject_id', $r->id)->count())->toBe(1);

    $this->get("/mci/{$p->manv_incident_id}")->assertInertia(fn ($page) => $page->has('patients', 0)->has('resources', 0));
});

it('leaves the resource on site while another patient still occupies it', function () {
    $i = mvd_incident();
    $p = mvd_ready($i);
    $r = ManvResource::find($p->manv_resource_id);
    mvd_ready($i, resource: $r);

    $this->actingAs(mvd_user('mci.manage'))->post("/mci/{$i->id}/patients/{$p->id}/departure")->assertSessionHasNoErrors();
    expect($p->refresh()->departed_at)->not->toBeNull()->and($r->refresh()->departed_at)->toBeNull();
    expect(ManvLogEntry::where('action', 'ressource_abgefahren')->count())->toBe(0);
});

it('is idempotent: a second departure is a 422 without a second log entry', function () {
    $p = mvd_ready();
    $this->actingAs(mvd_user('mci.manage'));
    $this->post("/mci/{$p->manv_incident_id}/patients/{$p->id}/departure")->assertSessionHasNoErrors();
    $first = $p->refresh()->departed_at;
    $this->travel(5)->minutes();
    $this->postJson("/mci/{$p->manv_incident_id}/patients/{$p->id}/departure")->assertStatus(422);
    expect($p->refresh()->departed_at->equalTo($first))->toBeTrue()
        ->and(ManvLogEntry::where('action', 'patient_abfahrt')->count())->toBe(1);
});

it('refuses a patient that may not depart', function () {
    $p = mvd_ready(over: ['triage' => 'SK4']);
    $this->actingAs(mvd_user('mci.manage'))->post("/mci/{$p->manv_incident_id}/patients/{$p->id}/departure")->assertSessionHasErrors('departure');
    expect($p->refresh()->departed_at)->toBeNull()->and(ManvLogEntry::count())->toBe(0);
});

it('denies departure to viewers, on closed Lagen and across Lagen', function () {
    $p = mvd_ready();
    $this->actingAs(mvd_user('mci.view'))->post("/mci/{$p->manv_incident_id}/patients/{$p->id}/departure")->assertForbidden();
    $this->actingAs(mvd_user('mci.manage'));
    $this->get("/mci/{$p->manv_incident_id}/patients/{$p->id}/departure")->assertStatus(405);
    $this->post('/mci/'.mvd_incident()->id."/patients/{$p->id}/departure")->assertNotFound();
    $closed = mvd_ready(mvd_incident(['status' => 'closed']));
    $this->post("/mci/{$closed->manv_incident_id}/patients/{$closed->id}/departure")->assertForbidden();
    expect($p->refresh()->departed_at)->toBeNull()->and($closed->refresh()->departed_at)->toBeNull();
});
