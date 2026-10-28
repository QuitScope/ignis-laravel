<?php

use App\Models\Poi;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ManvBoard\Enums\Triage;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mvp_user(string ...$perms): User
{
    $role = Role::create(['name' => 'P'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function mvp_incident(array $over = []): ManvIncident
{
    $i = (new ManvIncident)->forceFill($over + ['incident_number' => 'L-'.uniqid(), 'location' => 'Halle', 'started_at' => now()]);
    $i->save();

    return $i;
}

function mvp_resource(ManvIncident $i, array $over = []): ManvResource
{
    $r = (new ManvResource)->forceFill($over + ['manv_incident_id' => $i->id, 'type' => 'vehicle', 'label' => 'RTW '.uniqid()]);
    $r->save();

    return $r;
}

function mvp_patient(ManvIncident $i, array $over = []): ManvPatient
{
    $p = (new ManvPatient)->forceFill($over + ['manv_incident_id' => $i->id, 'number' => 'MANV-'.uniqid()]);
    $p->save();

    return $p;
}

function mvp_hospital(array $over = []): Poi
{
    return Poi::factory()->create($over + ['type' => 'Krankenhaus', 'name' => 'Klinikum '.uniqid()]);
}

it('shows the create page with free vehicles and active hospitals only', function () {
    $i = mvp_incident();
    $free = mvp_resource($i, ['label' => 'RTW frei']);
    $busy = mvp_resource($i, ['label' => 'RTW belegt']);
    mvp_patient($i, ['manv_resource_id' => $busy->id]);
    mvp_resource($i, ['label' => 'Weg', 'departed_at' => now()]);
    mvp_resource($i, ['label' => 'Trupp', 'type' => 'personnel']);
    mvp_resource(mvp_incident(), ['label' => 'Fremd']);
    $h = mvp_hospital(['name' => 'Klinikum Nord']);
    mvp_hospital(['is_active' => false]);
    Poi::factory()->create(['type' => 'Rettungswache']);

    $this->actingAs(mvp_user('mci.manage'))->get("/mci/{$i->id}/patients/create")->assertInertia(fn ($p) => $p->component('ManvBoard/PatientCreate')
        ->where('vehicles', fn ($v) => collect($v)->pluck('id')->all() === [$free->id])
        ->where('hospitals', fn ($hs) => collect($hs)->pluck('id')->all() === [$h->id]));
});

it('creates patients with consecutive numbers, snapshot, creator, log and audit', function () {
    $i = mvp_incident();
    $r = mvp_resource($i, ['label' => 'RTW 1', 'vehicle_type' => 'RTW', 'location' => 'Sammelplatz']);
    $h = mvp_hospital(['name' => 'Klinikum Nord']);
    $m = mvp_user('mci.manage');
    $this->actingAs($m);

    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'm', 'last_name' => 'Muster', 'manv_resource_id' => $r->id, 'destination_poi_id' => $h->id, 'number' => 'X', 'created_by' => 1])
        ->assertRedirect();
    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK3', 'sex' => 'unknown'])->assertRedirect();

    $first = ManvPatient::where('number', 'MANV-001')->sole();
    expect($first->triage)->toBe(Triage::SK1)->and($first->triaged_by)->toBe($m->id)->and($first->triaged_at)->not->toBeNull()
        ->and($first->callsign)->toBe('RTW 1')->and($first->vehicle_type)->toBe('RTW')->and($first->vehicle_location)->toBe('Sammelplatz')
        ->and($first->destination)->toBe('Klinikum Nord')->and($first->destination_poi_id)->toBe($h->id)
        ->and($first->created_by)->toBe($m->id)->and($first->last_name)->toBe('Muster');
    expect(ManvPatient::where('number', 'MANV-002')->exists())->toBeTrue();
    expect(ManvLogEntry::where('action', 'patient_erstellt')->count())->toBe(2);
    $audit = Activity::where('log_name', 'MANV')->latest('id')->first();
    expect($audit->details)->toContain('MANV-002')->not->toContain('Muster');
});

it('validates required and enum fields', function () {
    $i = mvp_incident();
    $this->actingAs(mvp_user('mci.manage'));
    $this->post("/mci/{$i->id}/patients", ['sex' => 'm'])->assertSessionHasErrors('triage');
    $this->post("/mci/{$i->id}/patients", ['triage' => 'tot', 'sex' => 'm'])->assertSessionHasErrors('triage');
    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'w'])->assertSessionHasErrors('sex');
    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'm', 'birth_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('birth_date');
    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'm', 'last_name' => str_repeat('x', 256)])->assertSessionHasErrors('last_name');
    expect(ManvPatient::count())->toBe(0);
});

it('rejects vehicles of another Lage, non-vehicles, departed and busy vehicles', function () {
    $i = mvp_incident();
    $busy = mvp_resource($i);
    mvp_patient($i, ['number' => 'MANV-001', 'manv_resource_id' => $busy->id]);
    $this->actingAs(mvp_user('mci.manage'));
    $body = ['triage' => 'SK1', 'sex' => 'm'];

    $this->post("/mci/{$i->id}/patients", $body + ['manv_resource_id' => mvp_resource(mvp_incident())->id])->assertSessionHasErrors('manv_resource_id');
    $this->post("/mci/{$i->id}/patients", $body + ['manv_resource_id' => mvp_resource($i, ['type' => 'material'])->id])->assertSessionHasErrors('manv_resource_id');
    $this->post("/mci/{$i->id}/patients", $body + ['manv_resource_id' => mvp_resource($i, ['departed_at' => now()])->id])->assertSessionHasErrors('manv_resource_id');
    $this->post("/mci/{$i->id}/patients", $body + ['manv_resource_id' => $busy->id])->assertSessionHasErrors('manv_resource_id');
    $this->postJson("/mci/{$i->id}/patients", $body + ['manv_resource_id' => $busy->id])->assertStatus(422);
    expect(ManvPatient::count())->toBe(1);

    ManvPatient::query()->update(['departed_at' => now()]);
    $this->post("/mci/{$i->id}/patients", $body + ['manv_resource_id' => $busy->id])->assertSessionHasNoErrors();
});

it('accepts a hospital or "Kein Transport" as destination, nothing else from the POI list', function () {
    $i = mvp_incident();
    $this->actingAs(mvp_user('mci.manage'));
    $body = ['triage' => 'SK1', 'sex' => 'm'];

    $this->post("/mci/{$i->id}/patients", $body + ['destination_poi_id' => mvp_hospital(['is_active' => false])->id])->assertSessionHasErrors('destination_poi_id');
    $this->post("/mci/{$i->id}/patients", $body + ['destination_poi_id' => Poi::factory()->create(['type' => 'Rettungswache'])->id])->assertSessionHasErrors('destination_poi_id');
    $this->post("/mci/{$i->id}/patients", $body + ['destination' => 'Kein Transport'])->assertSessionHasNoErrors();
    expect(ManvPatient::sole()->destination)->toBe('Kein Transport');
});

it('shows a patient with personal data to viewers and hides patients of other Lagen', function () {
    $i = mvp_incident();
    $p = mvp_patient($i, ['last_name' => 'Muster', 'injuries' => 'Fraktur', 'triage' => 'SK2']);
    $foreign = mvp_patient(mvp_incident());
    $this->actingAs(mvp_user('mci.view'));

    $this->get("/mci/{$i->id}/patients/{$p->id}")->assertInertia(fn ($page) => $page->component('ManvBoard/PatientShow')
        ->where('patient.last_name', 'Muster')->where('patient.injuries', 'Fraktur')->where('can.write', false));
    $this->get("/mci/{$i->id}/patients/{$foreign->id}")->assertNotFound();
    $this->get("/mci/{$i->id}/patients/create")->assertForbidden();
    $this->actingAs(mvp_user('audit.view'))->get("/mci/{$i->id}/patients/{$p->id}")->assertForbidden();
});

it('updates a patient, logs a real triage change separately and moves the vehicle snapshot', function () {
    $i = mvp_incident();
    $old = mvp_resource($i, ['label' => 'RTW alt']);
    $new = mvp_resource($i, ['label' => 'RTW neu', 'vehicle_type' => 'RTW']);
    $p = mvp_patient($i, ['triage' => 'SK2', 'manv_resource_id' => $old->id, 'callsign' => 'RTW alt']);
    $m = mvp_user('mci.manage');
    $this->actingAs($m);

    $this->put("/mci/{$i->id}/patients/{$p->id}", ['triage' => 'SK1', 'sex' => 'f', 'first_name' => 'Erika', 'manv_resource_id' => $new->id])->assertRedirect();
    $p->refresh();
    expect($p->triage)->toBe(Triage::SK1)->and($p->first_name)->toBe('Erika')->and($p->callsign)->toBe('RTW neu')->and($p->vehicle_type)->toBe('RTW')
        ->and($p->updated_by)->toBe($m->id);
    expect(ManvLogEntry::where('action', 'sichtung_geaendert')->count())->toBe(1)
        ->and(ManvLogEntry::where('action', 'patient_aktualisiert')->count())->toBe(1);

    $this->put("/mci/{$i->id}/patients/{$p->id}", ['triage' => 'SK1', 'sex' => 'f']);
    $p->refresh();
    expect($p->manv_resource_id)->toBeNull()->and($p->callsign)->toBeNull();
    expect(ManvLogEntry::where('action', 'sichtung_geaendert')->count())->toBe(1);
});

it('keeps its own vehicle selectable when editing', function () {
    $i = mvp_incident();
    $r = mvp_resource($i);
    $p = mvp_patient($i, ['manv_resource_id' => $r->id]);
    $this->actingAs(mvp_user('mci.manage'));

    $this->get("/mci/{$i->id}/patients/{$p->id}")->assertInertia(fn ($page) => $page->where('vehicles.0.id', $r->id));
    $this->put("/mci/{$i->id}/patients/{$p->id}", ['triage' => 'SK1', 'sex' => 'm', 'manv_resource_id' => $r->id])->assertSessionHasNoErrors();
});

it('denies patient writes to viewers and on closed or archived Lagen', function () {
    $this->actingAs(mvp_user('mci.view'));
    $i = mvp_incident();
    $p = mvp_patient($i);
    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'm'])->assertForbidden();
    $this->put("/mci/{$i->id}/patients/{$p->id}", ['triage' => 'SK1', 'sex' => 'm'])->assertForbidden();

    $this->actingAs(mvp_user('mci.manage'));
    foreach (['closed', 'archived'] as $status) {
        $c = mvp_incident(['status' => $status]);
        $cp = mvp_patient($c, ['last_name' => 'Alt']);
        $this->get("/mci/{$c->id}/patients/create")->assertForbidden();
        $this->post("/mci/{$c->id}/patients", ['triage' => 'SK1', 'sex' => 'm'])->assertForbidden();
        $this->put("/mci/{$c->id}/patients/{$cp->id}", ['triage' => 'SK1', 'sex' => 'm', 'last_name' => 'Neu'])->assertForbidden();
        expect($cp->refresh()->last_name)->toBe('Alt');
    }
    $super = User::factory()->superAdmin()->create();
    $this->actingAs($super)->post("/mci/{$c->id}/patients", ['triage' => 'SK1', 'sex' => 'm'])->assertForbidden();
});

it('stores markup in names and injuries verbatim and never renders it as HTML', function () {
    $i = mvp_incident();
    $this->actingAs(mvp_user('mci.manage'));
    $xss = '<img src=x onerror=alert(1)>';
    $this->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'm', 'last_name' => $xss, 'injuries' => $xss]);
    $p = ManvPatient::sole();
    expect($p->last_name)->toBe($xss);
    $this->get("/mci/{$i->id}/patients/{$p->id}")->assertInertia(fn ($page) => $page->where('patient.last_name', $xss));

    $files = [...glob(resource_path('js/Pages/ManvBoard/*.vue')), ...glob(resource_path('js/components/manvboard/*.vue'))];
    expect($files)->not->toBeEmpty();
    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('v-html');
    }
});

it('accepts as destination only "Kein Transport", the chosen hospital name or the unchanged stored text', function () {
    $i = mvp_incident();
    $h = mvp_hospital(['name' => 'Klinikum Nord']);
    $this->actingAs(mvp_user('mci.manage'));
    $body = ['triage' => 'SK1', 'sex' => 'm'];

    $this->post("/mci/{$i->id}/patients", $body + ['destination' => 'Irgendwo'])->assertSessionHasErrors('destination');
    $this->post("/mci/{$i->id}/patients", $body + ['destination_poi_id' => $h->id, 'destination' => 'Fälschung'])->assertSessionHasErrors('destination');
    expect(ManvPatient::count())->toBe(0);
    $this->post("/mci/{$i->id}/patients", $body + ['destination_poi_id' => $h->id, 'destination' => 'Klinikum Nord'])->assertSessionHasNoErrors();

    $imported = mvp_patient($i, ['destination' => 'Altklinik (Import)']);
    $this->put("/mci/{$i->id}/patients/{$imported->id}", ['sex' => 'm', 'destination' => 'Altklinik (Import)'])->assertSessionHasNoErrors();
    expect($imported->refresh()->destination)->toBe('Altklinik (Import)');
    $this->put("/mci/{$i->id}/patients/{$imported->id}", ['sex' => 'm', 'destination' => 'Neue Klinik'])->assertSessionHasErrors('destination');
});

it('keeps the vehicle snapshot of a patient whose resource no longer exists', function () {
    $i = mvp_incident();
    $p = mvp_patient($i, ['callsign' => 'RTW alt', 'vehicle_type' => 'RTW', 'vehicle_location' => 'Sammelplatz', 'departed_at' => now()]);
    $q = mvp_patient($i, ['callsign' => 'RTW weg', 'vehicle_type' => 'KTW']);
    $this->actingAs(mvp_user('mci.manage'));

    $this->put("/mci/{$i->id}/patients/{$p->id}", ['sex' => 'm', 'notes' => 'Nachtrag'])->assertSessionHasNoErrors();
    $this->put("/mci/{$i->id}/patients/{$q->id}", ['sex' => 'm', 'notes' => 'Nachtrag'])->assertSessionHasNoErrors();
    expect([$p->refresh()->callsign, $p->vehicle_type, $p->vehicle_location, $p->notes])->toBe(['RTW alt', 'RTW', 'Sammelplatz', 'Nachtrag'])
        ->and([$q->refresh()->callsign, $q->vehicle_type])->toBe(['RTW weg', 'KTW']);
});

it('freezes vehicle, destination and triage of a departed patient but keeps the rest editable', function () {
    $i = mvp_incident();
    $r = mvp_resource($i, ['departed_at' => now()]);
    $other = mvp_resource($i);
    $h = mvp_hospital(['name' => 'Klinikum Nord']);
    $p = mvp_patient($i, ['triage' => 'SK1', 'manv_resource_id' => $r->id, 'callsign' => $r->label, 'destination_poi_id' => $h->id, 'destination' => 'Klinikum Nord', 'departed_at' => now()]);
    $this->actingAs(mvp_user('mci.manage'));
    $same = ['sex' => 'm', 'manv_resource_id' => $r->id, 'destination_poi_id' => $h->id];

    $this->put("/mci/{$i->id}/patients/{$p->id}", ['manv_resource_id' => $other->id] + $same)->assertSessionHasErrors('manv_resource_id');
    $this->put("/mci/{$i->id}/patients/{$p->id}", ['manv_resource_id' => null] + $same)->assertSessionHasErrors('manv_resource_id');
    $this->put("/mci/{$i->id}/patients/{$p->id}", ['destination_poi_id' => null, 'destination' => 'Kein Transport'] + $same)->assertSessionHasErrors('destination_poi_id');
    $this->put("/mci/{$i->id}/patients/{$p->id}", ['triage' => 'SK2'] + $same)->assertSessionHasErrors('triage');
    $this->patch("/mci/{$i->id}/patients/{$p->id}/triage", ['triage' => 'SK2'])->assertSessionHasErrors('triage');
    expect([$p->refresh()->manv_resource_id, $p->destination, $p->triage->value])->toBe([$r->id, 'Klinikum Nord', 'SK1']);

    $this->put("/mci/{$i->id}/patients/{$p->id}", ['triage' => 'SK1', 'last_name' => 'Spät', 'injuries' => 'Nachtrag'] + $same)->assertSessionHasNoErrors();
    expect([$p->refresh()->last_name, $p->injuries, $p->manv_resource_id])->toBe(['Spät', 'Nachtrag', $r->id]);
});

it('re-reads the patient under lock, so a departure committed meanwhile freezes the assignment', function () {
    $i = mvp_incident();
    $r = mvp_resource($i);
    $other = mvp_resource($i);
    $p = mvp_patient($i, ['triage' => 'SK1', 'manv_resource_id' => $r->id]);
    $done = false;
    DB::listen(function ($q) use (&$done, $p) {
        if (! $done && str_starts_with($q->sql, 'select * from "manv_patients"')) {
            $done = true;
            ManvPatient::query()->whereKey($p->id)->update(['departed_at' => now()]);
        }
    });
    $this->actingAs(mvp_user('mci.manage'))->put("/mci/{$i->id}/patients/{$p->id}", ['sex' => 'm', 'manv_resource_id' => $other->id])->assertSessionHasErrors('manv_resource_id');
    expect($done)->toBeTrue()->and($p->refresh()->manv_resource_id)->toBe($r->id);
});

it('turns a lost race for the patient number into a validation error instead of a 500', function () {
    $i = mvp_incident();
    $done = false;
    DB::listen(function ($q) use (&$done, $i) {
        if (! $done && str_starts_with($q->sql, 'select "number" from "manv_patients"')) {
            $done = true;
            DB::table('manv_patients')->insert(['manv_incident_id' => $i->id, 'number' => 'MANV-001', 'sex' => 'unknown']);
        }
    });
    $this->actingAs(mvp_user('mci.manage'))->post("/mci/{$i->id}/patients", ['triage' => 'SK1', 'sex' => 'm'])->assertSessionHasErrors('number');
    expect($done)->toBeTrue();
});
