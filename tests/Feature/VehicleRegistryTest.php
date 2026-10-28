<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRegistryEntry;
use App\Support\Settings;
use App\Vehicles\VehicleRegistryIntake;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function vr_user(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function vr_entry(array $attrs = []): VehicleRegistryEntry
{
    return VehicleRegistryEntry::factory()->create(['name' => 'RTW 1/83-1', 'identifier' => 'rtw_1_83-1', 'type' => 'RTW', 'service' => 2, 'job' => 'ambulance, doctor', ...$attrs]);
}

// ----- rights -----

it('guards every route with vehicle.manageImport', function () {
    $e = vr_entry();
    $routes = [['get', '/vehicles/registry'], ['get', '/vehicles/registry/status'], ['post', '/vehicles/registry/request'],
        ['post', '/vehicles/registry/ignore-all'], ['post', '/vehicles/registry/cancel-request'], ['post', "/vehicles/registry/{$e->id}/import"], ['post', "/vehicles/registry/{$e->id}/overwrite"],
        ['post', "/vehicles/registry/{$e->id}/merge"], ['post', "/vehicles/registry/{$e->id}/ignore"]];
    foreach ($routes as [$method, $url]) {
        $this->{$method}($url)->assertRedirect('/login');
    }
    foreach ([vr_user('vehicles.view'), vr_user('audit.view')] as $user) {
        foreach ($routes as [$method, $url]) {
            $this->actingAs($user)->{$method}($url)->assertForbidden();
        }
    }
    foreach ([vr_user('vehicles.manage'), vr_user('admin'), User::factory()->superAdmin()->create()] as $user) {
        $this->actingAs($user)->get('/vehicles/registry')->assertOk();
        $this->actingAs($user)->getJson('/vehicles/registry/status')->assertOk();
    }
    expect(VehicleRegistryEntry::sole()->status)->toBe('pending');
});

it('drops the old dispatcher', function () {
    $this->actingAs(vr_user('admin'))->post('/api/vehicles/import-handler', ['action' => 'list'])->assertNotFound();
    $this->actingAs(vr_user('admin'))->get('/api/vehicles/import-handler?action=status')->assertNotFound();
});

it('only knows the four actions', function () {
    $e = vr_entry();
    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/delete")->assertNotFound();
    $this->actingAs(vr_user('admin'))->post('/vehicles/registry/abc/import')->assertNotFound();
    $this->actingAs(vr_user('admin'))->post('/vehicles/registry/999999/import')->assertNotFound();
});

// ----- list -----

it('lists pending entries with their match against the vehicles', function () {
    $byName = Vehicle::factory()->create(['name' => 'rtw 1/83-1', 'identifier' => 'other']);
    $byId = Vehicle::factory()->create(['name' => 'Something', 'identifier' => 'NEF_1']);
    vr_entry();
    vr_entry(['name' => 'NEF 1', 'identifier' => 'nef_1']);
    vr_entry(['name' => 'Neu', 'identifier' => 'neu']);
    vr_entry(['name' => 'Done', 'status' => 'accepted']);

    $this->actingAs(vr_user('vehicles.manage'))->get('/vehicles/registry')->assertInertia(fn (Assert $p) => $p
        ->component('Vehicles/Registry')
        ->has('entries.data', 3)
        ->where('entries.data.0.match_type', 'name')->where('entries.data.0.existing.id', $byName->id)
        ->where('entries.data.1.match_type', 'identifier')->where('entries.data.1.existing.id', $byId->id)
        ->where('entries.data.2.match_type', 'none')->where('entries.data.2.existing', null)
        ->where('counts', ['pending' => 3, 'matched' => 2, 'new' => 1, 'max_id' => VehicleRegistryEntry::where('status', 'pending')->max('id')])
        ->where('status.pending', false)->where('status.count', 3)
        ->where('can.view', false));
});

it('prefers a name match over an identifier match', function () {
    $idMatch = Vehicle::factory()->create(['name' => 'X', 'identifier' => 'rtw_1_83-1']);
    $nameMatch = Vehicle::factory()->create(['name' => 'RTW 1/83-1', 'identifier' => 'y']);
    vr_entry();

    $this->actingAs(vr_user('admin'))->get('/vehicles/registry')->assertInertia(fn (Assert $p) => $p
        ->where('entries.data.0.match_type', 'name')->where('entries.data.0.existing.id', $nameMatch->id));
    expect($idMatch->id)->toBeLessThan($nameMatch->id);
});

// ----- request and status -----

it('requests the registry and reports the state for polling', function () {
    $this->travelTo('2026-10-19 12:00:00');
    $u = vr_user('vehicles.manage');

    $this->actingAs($u)->getJson('/vehicles/registry/status')
        ->assertExactJson(['pending' => false, 'count' => 0, 'requested' => null, 'last_sync_at' => null]);

    $this->actingAs($u)->post('/vehicles/registry/request')->assertRedirect()->assertSessionHas('status', 'vehicles.registry.requested');

    expect(app(Settings::class)->get('vehicles.registry_requested_at'))->toBe(now()->toIso8601String());
    $this->actingAs($u)->getJson('/vehicles/registry/status')
        ->assertExactJson(['pending' => true, 'count' => 0, 'requested' => now()->toIso8601String(), 'last_sync_at' => null]);
    $a = Activity::sole();
    expect($a->description)->toBe('EMD Fahrzeug-Import angefordert')->and($a->log_name)->toBe('Fahrzeuge')
        ->and($a->getAttribute('is_global'))->toBeTruthy()->and($a->causer_id)->toBe($u->id);
});

// ----- import -----

it('imports an entry as a new vehicle', function () {
    $u = vr_user('vehicles.manage');
    $e = vr_entry();

    $this->actingAs($u)->post("/vehicles/registry/{$e->id}/import")->assertRedirect()->assertSessionHasNoErrors();

    $v = Vehicle::sole();
    expect($v->only(['name', 'identifier', 'type', 'service', 'sort_order', 'is_active', 'license_plate', 'allowed_jobs']))->toBe([
        'name' => 'RTW 1/83-1', 'identifier' => 'rtw_1_83-1', 'type' => 'RTW', 'service' => 2, 'sort_order' => 0, 'is_active' => true,
        'license_plate' => null, 'allowed_jobs' => ['ambulance', 'doctor'],
    ]);
    $e->refresh();
    expect($e->status)->toBe('accepted')->and($e->processed_by)->toBe($u->id)->and($e->processed_at)->not->toBeNull();
    $a = Activity::sole();
    expect($a->description)->toBe('Fahrzeug per EMD-Import erstellt')->and($a->getAttribute('details'))->toBe('Name: RTW 1/83-1 | Typ: RTW')
        ->and($a->log_name)->toBe('Fahrzeuge')->and($a->properties['context'])->toBe(['id' => $v->id]);
})->group('mariadb');

it('takes the form overrides on import', function () {
    $e = vr_entry(['type' => null, 'job' => null]);

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/import", [
        'name' => ' RTW 7 ', 'identifier' => 'rtw-7', 'type' => 'ITW', 'service' => '1', 'allowed_jobs' => "ems,\nfire, EMS",
    ])->assertSessionHasNoErrors();

    expect(Vehicle::sole()->only(['name', 'identifier', 'type', 'service', 'allowed_jobs']))
        ->toBe(['name' => 'RTW 7', 'identifier' => 'rtw-7', 'type' => 'ITW', 'service' => 1, 'allowed_jobs' => ['ems', 'fire']]);
});

it('imports an entry without type or job as an untyped vehicle for all jobs', function () {
    $e = vr_entry(['type' => null, 'job' => null, 'service' => 0]);

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/import")->assertSessionHasNoErrors();

    expect(Vehicle::sole()->only(['type', 'service', 'allowed_jobs']))->toBe(['type' => '', 'service' => 0, 'allowed_jobs' => null]);
});

it('refuses to import a duplicate by name or identifier', function (array $existing) {
    Vehicle::factory()->create($existing);
    $e = vr_entry();

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/import")->assertSessionHasErrors(['entry' => trans('vehicles.registry.error.duplicate')]);
    $this->actingAs(vr_user('admin'))->postJson("/vehicles/registry/{$e->id}/import")->assertStatus(422);

    expect(Vehicle::count())->toBe(1)->and($e->fresh()->status)->toBe('pending')->and(Activity::count())->toBe(0);
})->with([
    'name' => [['name' => 'RTW 1/83-1']],
    'name, other case' => [['name' => 'rtw 1/83-1']],
    'identifier' => [['identifier' => 'RTW_1_83-1']],
]);

it('refuses a duplicate of the overridden name too', function () {
    Vehicle::factory()->create(['name' => 'Taken']);
    $e = vr_entry();

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/import", ['name' => 'taken'])->assertSessionHasErrors('entry');
    expect(Vehicle::count())->toBe(1);
});

// ----- each action once -----

it('runs every action once and answers 409 the second time', function (string $action) {
    $v = Vehicle::factory()->create(['name' => 'RTW 1/83-1']);
    $e = vr_entry();
    $u = vr_user('admin');
    $data = in_array($action, ['overwrite', 'merge'], true) ? ['vehicle_id' => $v->id] : [];
    if ($action === 'import') {
        $v->delete();
    }

    $this->actingAs($u)->post("/vehicles/registry/{$e->id}/{$action}", $data)->assertRedirect()->assertSessionHasNoErrors();
    $audits = Activity::count();
    $vehicle = Vehicle::first()?->toArray();

    $this->actingAs($u)->postJson("/vehicles/registry/{$e->id}/{$action}", $data)->assertStatus(409);
    $this->actingAs($u)->post("/vehicles/registry/{$e->id}/{$action}", $data)->assertSessionHasErrors(['entry' => trans('vehicles.registry.error.processed')]);

    expect(Activity::count())->toBe($audits)->and(Vehicle::first()?->toArray())->toBe($vehicle)->and(Vehicle::count())->toBeLessThanOrEqual(1);
})->group('mariadb')->with(['import', 'overwrite', 'merge', 'ignore']);

it('answers 409 for an entry decided before, whatever the action', function (string $status, string $action) {
    $v = Vehicle::factory()->create();
    $e = vr_entry(['status' => $status]);

    $this->actingAs(vr_user('admin'))->postJson("/vehicles/registry/{$e->id}/{$action}", ['vehicle_id' => $v->id])->assertStatus(409);

    expect($e->fresh()->status)->toBe($status)->and(Vehicle::count())->toBe(1);
})->with(['accepted', 'rejected'])->with(['import', 'overwrite', 'merge', 'ignore']);

// ----- overwrite and merge -----

it('overwrites name, identifier, type, service and jobs of the chosen vehicle', function () {
    $v = Vehicle::factory()->create(['name' => 'Alt', 'identifier' => 'alt', 'type' => 'KTW', 'service' => 0, 'license_plate' => 'HH-1', 'sort_order' => 5, 'allowed_jobs' => ['fire']]);
    $e = vr_entry();

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/overwrite", ['vehicle_id' => $v->id])->assertSessionHasNoErrors();

    expect($v->fresh()->only(['name', 'identifier', 'type', 'service', 'allowed_jobs', 'license_plate', 'sort_order']))->toBe([
        'name' => 'RTW 1/83-1', 'identifier' => 'rtw_1_83-1', 'type' => 'RTW', 'service' => 2, 'allowed_jobs' => ['ambulance', 'doctor'],
        'license_plate' => 'HH-1', 'sort_order' => 5,
    ]);
    $a = Activity::sole();
    expect($a->description)->toBe('Fahrzeug per EMD-Import überschrieben')->and($a->getAttribute('details'))->toBe("Name: RTW 1/83-1 | ID: {$v->id}")
        ->and($a->properties['context'])->toBe(['id' => $v->id])->and($e->fresh()->status)->toBe('accepted');
})->group('mariadb');

it('names the entry, not the form, in the merge audit', function () {
    $v = Vehicle::factory()->create();
    $e = vr_entry();

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/merge", ['vehicle_id' => $v->id, 'name' => 'Formname'])->assertSessionHasNoErrors();

    expect(Activity::sole()->getAttribute('details'))->toBe("Name: RTW 1/83-1 | ID: {$v->id}");
});

it('fits the EMD job text into the allowed jobs limits', function (string $job, ?array $expected) {
    $e = vr_entry(['job' => $job]);

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/import")->assertSessionHasNoErrors();

    expect(Vehicle::sole()->allowed_jobs)->toBe($expected);
})->with([
    'overlong job dropped' => [str_repeat('x', 150).', ems', ['ems']],
    'only overlong' => [str_repeat('x', 150), null],
    '60 jobs cut to 50' => [implode(',', array_map(fn ($i) => 'j'.$i, range(1, 60))), array_map(fn ($i) => 'j'.$i, range(1, 50))],
]);

it('ignores without looking at the override fields', function () {
    $e = vr_entry();

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/ignore", ['service' => 99, 'name' => ['x'], 'vehicle_id' => 'junk'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($e->fresh()->status)->toBe('rejected');
});

it('cancels a waiting request', function () {
    $u = vr_user('vehicles.manage');
    $this->actingAs($u)->post('/vehicles/registry/request');

    $this->actingAs($u)->post('/vehicles/registry/cancel-request')->assertRedirect()->assertSessionHas('status', 'vehicles.registry.cancelled');

    expect(app(Settings::class)->has('vehicles.registry_requested_at'))->toBeFalse();
    $this->actingAs($u)->getJson('/vehicles/registry/status')->assertJson(['pending' => false, 'requested' => null]);
});

it('ignores all only up to the entries the page showed', function () {
    $u = vr_user('admin');
    $shown = VehicleRegistryEntry::factory()->count(2)->create();
    $this->actingAs($u)->get('/vehicles/registry')->assertInertia(fn (Assert $p) => $p->where('counts.max_id', $shown->last()->id));
    $later = vr_entry();

    $this->actingAs($u)->post('/vehicles/registry/ignore-all', ['max_id' => $shown->last()->id])->assertSessionHas('status_params', ['count' => 2]);

    expect($later->fresh()->status)->toBe('pending');
    $this->actingAs($u)->post('/vehicles/registry/ignore-all')->assertSessionHasErrors('max_id');
    $this->actingAs($u)->post('/vehicles/registry/ignore-all', ['max_id' => ['1']])->assertSessionHasErrors('max_id');
    expect($later->fresh()->status)->toBe('pending');
});

it('merges only into empty fields and the service only when it is other', function () {
    $full = Vehicle::factory()->create(['name' => 'Voll', 'identifier' => 'voll', 'type' => 'KTW', 'service' => 3, 'allowed_jobs' => ['fire']]);
    $empty = Vehicle::factory()->create(['name' => 'Leer', 'identifier' => '', 'type' => '', 'service' => 0, 'allowed_jobs' => null]);
    $e1 = vr_entry();
    $e2 = vr_entry(['name' => 'RTW 2']);

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e1->id}/merge", ['vehicle_id' => $full->id])->assertSessionHasNoErrors();
    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e2->id}/merge", ['vehicle_id' => $empty->id])->assertSessionHasNoErrors();

    expect($full->fresh()->only(['name', 'identifier', 'type', 'service', 'allowed_jobs']))
        ->toBe(['name' => 'Voll', 'identifier' => 'voll', 'type' => 'KTW', 'service' => 3, 'allowed_jobs' => ['fire']]);
    expect($empty->fresh()->only(['name', 'identifier', 'type', 'service', 'allowed_jobs']))
        ->toBe(['name' => 'Leer', 'identifier' => 'rtw_1_83-1', 'type' => 'RTW', 'service' => 2, 'allowed_jobs' => ['ambulance', 'doctor']]);
    $a = Activity::orderBy('id')->get();
    expect($a->pluck('description')->all())->toBe(['Fahrzeug per EMD-Import zusammengeführt', 'Fahrzeug per EMD-Import zusammengeführt'])
        ->and($a[1]->properties['context'])->toBe(['id' => $empty->id]);
});

it('needs an existing vehicle for overwrite and merge', function (string $action, array $data) {
    $e = vr_entry();

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/{$action}", $data)->assertSessionHasErrors('vehicle_id');

    expect($e->fresh()->status)->toBe('pending');
})->with(['overwrite', 'merge'])->with([
    'missing' => [[]], 'unknown' => [['vehicle_id' => 999999]], 'array' => [['vehicle_id' => [1]]], 'text' => [['vehicle_id' => 'x']],
]);

it('validates the overrides', function (array $data, string $field) {
    $e = vr_entry();

    $this->actingAs(vr_user('admin'))->post("/vehicles/registry/{$e->id}/import", $data)->assertSessionHasErrors($field);

    expect(Vehicle::count())->toBe(0)->and($e->fresh()->status)->toBe('pending');
})->with([
    [['service' => 4], 'service'], [['service' => 'x'], 'service'], [['type' => str_repeat('t', 256)], 'type'],
    [['name' => ['a']], 'name'], [['identifier' => str_repeat('i', 256)], 'identifier'], [['allowed_jobs' => str_repeat('j', 501)], 'allowed_jobs'],
    [['name' => "\xC3\x28"], 'name'], [['allowed_jobs' => implode(',', range(1, 51))], 'allowed_jobs'],
]);

// ----- ignore -----

it('ignores one entry or all pending ones without audit', function () {
    $u = vr_user('vehicles.manage');
    $one = vr_entry();
    $others = VehicleRegistryEntry::factory()->count(2)->create();
    $accepted = vr_entry(['status' => 'accepted']);

    $this->actingAs($u)->post("/vehicles/registry/{$one->id}/ignore")->assertRedirect();
    expect($one->fresh()->status)->toBe('rejected')->and($one->fresh()->processed_by)->toBe($u->id);

    $this->actingAs($u)->post('/vehicles/registry/ignore-all', ['max_id' => $accepted->id])->assertRedirect()
        ->assertSessionHas('status', 'vehicles.registry.ignored_all')->assertSessionHas('status_params', ['count' => 2]);
    expect($others->map->fresh()->pluck('status')->all())->toBe(['rejected', 'rejected'])
        ->and($accepted->fresh()->status)->toBe('accepted')->and(Activity::count())->toBe(0);
});

// ----- end to end -----

it('shows what a registry run brought in and clears the waiting state', function () {
    $u = vr_user('admin');
    $this->actingAs($u)->post('/vehicles/registry/request');

    $json = json_decode((string) file_get_contents(base_path('tests/Fixtures/emd/vehicle_registry.json')), true, 32, JSON_THROW_ON_ERROR);
    app(VehicleRegistryIntake::class)->accept($json['vehicle_registry']);

    $this->actingAs($u)->getJson('/vehicles/registry/status')->assertJson(['pending' => false, 'count' => 8, 'requested' => null])
        ->assertJsonPath('last_sync_at', fn ($v) => is_string($v));
});
