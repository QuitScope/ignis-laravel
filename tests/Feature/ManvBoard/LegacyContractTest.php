<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\ManvBoard\Enums\ManvIncidentStatus;
use Modules\ManvBoard\Enums\Triage;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function manvLegacyContractUser(string ...$perms): User
{
    $role = Role::create(['name' => 'LC'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    foreach ($perms as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function lc_incident(array $over = []): ManvIncident
{
    $i = (new ManvIncident)->forceFill($over + ['incident_number' => 'MANV-'.uniqid(), 'location' => 'A1 km 42', 'started_at' => now()]);
    $i->save();

    return $i;
}

function lc_resource(ManvIncident $i, string $label, array $over = []): ManvResource
{
    $r = (new ManvResource)->forceFill($over + ['manv_incident_id' => $i->id, 'type' => 'vehicle', 'label' => $label, 'vehicle_type' => 'RTW', 'location' => 'VSP Nord']);
    $r->save();

    return $r;
}

// ---- MciPolicyTest: legacy abilities viewList/view/create/update/delete => viewAny/view/create/update/delete ----

dataset('lc_abilities', [
    'viewList' => ['viewAny', fn () => ManvIncident::class, false],
    'view' => ['view', fn () => lc_incident(), false],
    'create' => ['create', fn () => ManvIncident::class, true],
    'update' => ['update', fn () => lc_incident(), true],
    'delete' => ['delete', fn () => lc_incident(), true],
]);

it('rejects an account without a right', function (string $ability, Closure $arg) {
    expect(manvLegacyContractUser('audit.view')->can($ability, $arg()))->toBeFalse();
})->with('lc_abilities');

it('passes admin and super admin', function (string $ability, Closure $arg) {
    expect(manvLegacyContractUser('admin')->can($ability, $arg()))->toBeTrue()
        ->and(User::factory()->superAdmin()->create()->can($ability, $arg()))->toBeTrue();
})->with('lc_abilities');

it('passes mci.manage for every ability', function (string $ability, Closure $arg) {
    expect(manvLegacyContractUser('mci.manage')->can($ability, $arg()))->toBeTrue();
})->with('lc_abilities');

it('rejects anonymous visitors', function (string $ability, Closure $arg) {
    expect(Gate::forUser(null)->allows($ability, $arg()))->toBeFalse();
})->with('lc_abilities');

it('lets mci.view read and nothing else (new)', function (string $ability, Closure $arg, bool $writes) {
    expect(manvLegacyContractUser('mci.view')->can($ability, $arg()))->toBe(! $writes);
})->with('lc_abilities');

it('does not let similar permission names through', function (string $ability, Closure $arg) {
    $user = manvLegacyContractUser('manv.manage', 'manv.view', 'mci.observer', 'mci.managed', 'mci.viewer');
    expect($user->can($ability, $arg()))->toBeFalse();
})->with('lc_abilities');

// ---- MciFormsTest ----

it('creates and edits a Lage', function () {
    $lna = Personnel::factory()->create(['full_name' => 'Dr. Lang']);
    $this->actingAs(manvLegacyContractUser('mci.manage'));

    $this->post('/mci', [
        'incident_number' => '  MANV-77  ', 'location' => 'Hauptbahnhof', 'reason' => 'Zugunglück',
        'started_at' => '2026-10-09T08:15', 'lna_personnel_id' => $lna->id, 'orgl_personnel_id' => '', 'notes' => 'Zwei Züge',
    ])->assertRedirect();

    $i = ManvIncident::sole();
    expect($i->incident_number)->toBe('MANV-77')->and($i->location)->toBe('Hauptbahnhof')->and($i->lna_name)->toBe('Dr. Lang')
        ->and($i->orgl_personnel_id)->toBeNull()->and($i->status)->toBe(ManvIncidentStatus::Active);

    $this->put("/mci/{$i->id}", ['incident_number' => 'MANV-77', 'location' => 'Hauptbahnhof Gleis 3', 'status' => 'closed'])->assertRedirect();
    $i->refresh();
    expect($i->location)->toBe('Hauptbahnhof Gleis 3')->and($i->status)->toBe(ManvIncidentStatus::Closed);
});

it('rejects a Lage without the required fields; foreign fields are ignored, never taken', function () {
    $this->actingAs($manager = manvLegacyContractUser('mci.manage'));

    $this->post('/mci', ['incident_number' => '   ', 'location' => 'Hauptbahnhof'])->assertSessionHasErrors('incident_number');
    $this->post('/mci', ['incident_number' => 'MANV-X', 'location' => ''])->assertSessionHasErrors('location');
    expect(ManvIncident::count())->toBe(0);

    $this->post('/mci', ['incident_number' => 'MANV-X', 'location' => 'Hauptbahnhof', 'created_by' => 999, 'status' => 'archived'])->assertSessionHasNoErrors();
    expect(ManvIncident::sole()->created_by)->toBe($manager->id)->and(ManvIncident::sole()->status)->toBe(ManvIncidentStatus::Active);
});

it('rejects an unknown status instead of turning it into active (deliberate change)', function () {
    $i = lc_incident();
    $this->actingAs(manvLegacyContractUser('mci.manage'))->put("/mci/{$i->id}", ['incident_number' => 'MANV-1', 'location' => 'Ort', 'status' => 'geloescht'])
        ->assertSessionHasErrors('status');
    expect($i->refresh()->status)->toBe(ManvIncidentStatus::Active);
});

it('creates a patient and changes the triage with a log entry', function () {
    $i = lc_incident();
    $rtw = lc_resource($i, 'RTW 1/83-1');
    $this->actingAs(manvLegacyContractUser('mci.manage'));

    $this->post("/mci/{$i->id}/patients", [
        'last_name' => 'Muster', 'first_name' => 'Max', 'birth_date' => '', 'sex' => 'm', 'triage' => 'SK2',
        'manv_resource_id' => $rtw->id, 'destination' => '', 'injuries' => 'Fraktur Unterarm', 'notes' => '',
    ])->assertRedirect();

    $p = ManvPatient::sole();
    expect($p->last_name)->toBe('Muster')->and($p->birth_date)->toBeNull()->and($p->triage->value)->toBe('SK2')
        ->and($p->callsign)->toBe('RTW 1/83-1')->and($p->vehicle_location)->toBe('VSP Nord');

    $this->put("/mci/{$i->id}/patients/{$p->id}", [
        'last_name' => 'Muster', 'first_name' => 'Max', 'sex' => 'm', 'triage' => 'SK1', 'manv_resource_id' => '', 'injuries' => 'Fraktur Unterarm, Schock',
    ])->assertSessionHasNoErrors();

    $p->refresh();
    expect($p->triage->value)->toBe('SK1')->and($p->injuries)->toBe('Fraktur Unterarm, Schock')->and($p->manv_resource_id)->toBeNull();
    expect(ManvLogEntry::where('manv_incident_id', $i->id)->where('action', 'sichtung_geaendert')->exists())->toBeTrue();
});

it('rejects a patient field sent as a list', function () {
    $i = lc_incident();
    $this->actingAs(manvLegacyContractUser('mci.manage'))->post("/mci/{$i->id}/patients", ['last_name' => ['Muster'], 'sex' => 'm', 'triage' => 'SK1'])
        ->assertSessionHasErrors('last_name');
    expect(ManvPatient::count())->toBe(0);
});

it('creates and edits a resource', function () {
    $i = lc_incident();
    $this->actingAs(manvLegacyContractUser('mci.manage'));

    $this->post("/mci/{$i->id}/resources", [
        'type' => 'vehicle', 'label' => ' RTW 2/83-1 ', 'vehicle_type' => 'RTW', 'location' => 'Haltepunkt Nord', 'notes' => '',
    ])->assertRedirect();

    $r = ManvResource::sole();
    expect($r->label)->toBe('RTW 2/83-1')->and($r->status->value)->toBe('available');

    $this->put("/mci/{$i->id}/resources/{$r->id}", ['label' => 'RTW 2/83-1', 'vehicle_type' => 'RTW', 'location' => 'Bereitstellungsraum', 'notes' => 'Nachgerückt'])
        ->assertSessionHasNoErrors();
    $r->refresh();
    expect($r->location)->toBe('Bereitstellungsraum')->and($r->notes)->toBe('Nachgerückt');
});

it('rejects a resource without a label', function () {
    $i = lc_incident();
    $this->actingAs(manvLegacyContractUser('mci.manage'))->postJson("/mci/{$i->id}/resources", ['type' => 'vehicle', 'label' => '  '])
        ->assertStatus(422)->assertJsonValidationErrors('label');
    expect(ManvResource::count())->toBe(0);
});

// ---- MciBoardTest: sorting triage then number, header cells, colour per category ----

it('sorts the table by triage then number and by a header column, unknown columns are rejected', function () {
    $i = lc_incident();
    foreach ([['P-02', 'SK3', 'Schulz'], ['P-03', 'SK1', 'Adler'], ['P-01', 'SK1', 'Müller'], ['P-04', 'SK2', 'Becker']] as [$nr, $sk, $name]) {
        (new ManvPatient)->forceFill(['manv_incident_id' => $i->id, 'number' => $nr, 'triage' => $sk, 'last_name' => $name])->save();
    }
    $this->actingAs(manvLegacyContractUser('mci.manage'));
    $numbers = fn ($p) => collect($p)->pluck('number')->all();

    $this->get("/mci/{$i->id}")->assertInertia(fn ($page) => $page
        ->where('patients', fn ($p) => $numbers($p) === ['P-01', 'P-03', 'P-04', 'P-02'])
        ->where('sort', ['key' => 'triage', 'dir' => 'asc']));
    $this->get("/mci/{$i->id}?sort=number&dir=desc")->assertInertia(fn ($page) => $page
        ->where('patients', fn ($p) => $numbers($p) === ['P-04', 'P-03', 'P-02', 'P-01'])
        ->where('sort', ['key' => 'number', 'dir' => 'desc']));

    $this->get("/mci/{$i->id}?sort=last_name&dir=desc")->assertSessionHasErrors('sort');
});

it('keeps the header cells and the colour per category in the board components', function () {
    $table = file_get_contents(base_path('resources/js/components/manvboard/PatientTable.vue'));
    foreach (['number', 'triage', 'vehicle', 'destination'] as $key) {
        expect($table)->toContain("key: '{$key}'");
    }
    expect($table)->toContain('aria-sort')->and($table)->toContain('?sort=');

    $lib = file_get_contents(base_path('resources/js/lib/manv.ts'));
    foreach (['SK1' => 'bg-danger', 'SK2' => 'bg-warn', 'SK3' => 'bg-ok', 'SK4' => 'bg-info', 'SK5' => 'bg-text'] as $sk => $tone) {
        expect($lib)->toMatch('/'.$sk.': \''.$tone.' /');
    }
});

it('offers the triage categories SK1 to SK6 and no "tot"', function () {
    expect(array_column(Triage::cases(), 'value'))->toBe(['SK1', 'SK2', 'SK3', 'SK4', 'SK5', 'SK6']);
});
