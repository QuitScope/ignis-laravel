<?php

use App\Models\Poi;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Support\AuditLogger;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function vh_user(string ...$perms): User
{
    $role = Role::create(['name' => 'V'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function vh_rows(mixed $test, User $u, string $qs = ''): array
{
    return $test->actingAs($u)->get('/vehicles?'.$qs)->viewData('page')['props']['vehicles']['data'];
}

function vh_valid(array $over = []): array
{
    return $over + ['name' => 'Florian 1/83-1', 'type' => 'RTW', 'identifier' => 'rtw_1', 'service' => 2, 'sort_order' => 3, 'is_active' => true];
}

// ----- rights -----

it('guards every route: guest to login, view reads, manage writes', function () {
    $v = Vehicle::factory()->create();
    $routes = [
        ['get', '/vehicles', 'view'], ['get', "/vehicles/{$v->id}", 'view'],
        ['get', '/vehicles/create', 'manage'], ['get', "/vehicles/{$v->id}/edit", 'manage'],
        ['post', '/vehicles', 'manage'], ['put', "/vehicles/{$v->id}", 'manage'], ['delete', "/vehicles/{$v->id}", 'manage'],
        ['post', '/vehicles/bulk', 'manage'],
    ];
    $viewer = vh_user('vehicles.view');
    $manager = vh_user('vehicles.manage');
    $nobody = vh_user('audit.view');

    foreach ($routes as [$method, $url]) {
        $this->{$method}($url)->assertRedirect('/login');
    }
    foreach ($routes as [$method, $url, $right]) {
        $this->actingAs($nobody)->{$method}($url)->assertForbidden();
        $this->actingAs($right === 'view' ? $manager : $viewer)->{$method}($url)->assertForbidden();
        $this->actingAs($right === 'view' ? $viewer : $manager)->{$method}($url)->assertStatus($method === 'get' ? 200 : 302);
    }
});

it('lets admins do everything', function () {
    $admin = vh_user('admin');
    $v = Vehicle::factory()->create();
    $this->actingAs($admin)->get('/vehicles')->assertOk();
    $this->actingAs($admin)->get("/vehicles/{$v->id}")->assertOk();
    $this->actingAs($admin)->get("/vehicles/{$v->id}/edit")->assertOk();
});

it('answers 404 for unknown, malformed and overflowing ids', function () {
    $admin = vh_user('admin');
    foreach (['/vehicles/999', '/vehicles/abc', '/vehicles/99999999999999999999', '/vehicles/999/edit'] as $url) {
        $this->actingAs($admin)->get($url)->assertNotFound();
    }
});

// ----- list -----

it('lists vehicles with both the admin switch and the derived operability', function () {
    $ok = Vehicle::factory()->create(['name' => 'A', 'sort_order' => 1]);
    $blocked = Vehicle::factory()->create(['name' => 'B', 'sort_order' => 2]);
    VehicleDefect::factory()->for($blocked)->blocking()->create();
    VehicleDefect::factory()->for($blocked)->create();
    $off = Vehicle::factory()->inactive()->create(['name' => 'C', 'sort_order' => 3]);

    $rows = vh_rows($this, vh_user('vehicles.view'));

    expect(collect($rows)->map(fn ($r) => [$r['name'], $r['is_active'], $r['state'], $r['open_defects_count'], $r['blocking_defects_count']])->all())
        ->toBe([['A', true, 'operable', 0, 0], ['B', true, 'blocked', 2, 1], ['C', false, 'inactive', 0, 0]]);
    expect($ok->fresh()->is_active)->toBeTrue();
    expect($off->id)->toBeInt();
});

it('searches name, plate, identifier and type and treats % and _ literally', function () {
    Vehicle::factory()->create(['name' => 'Florian 1', 'license_plate' => 'HH-100', 'identifier' => 'a1', 'type' => 'HLF']);
    Vehicle::factory()->create(['name' => '100% Wagen', 'identifier' => 'b2', 'type' => 'RTW']);
    Vehicle::factory()->create(['name' => 'A_B', 'identifier' => 'c3', 'type' => 'KTW']);
    Vehicle::factory()->create(['name' => 'AxB', 'identifier' => 'd4', 'type' => 'NEF']);
    $u = vh_user('vehicles.view');

    $names = fn (string $q) => collect(vh_rows($this, $u, 'search='.urlencode($q)))->pluck('name')->all();
    expect($names('HH-1'))->toBe(['Florian 1'])
        ->and($names('hlf'))->toBe(['Florian 1'])
        ->and($names('b2'))->toBe(['100% Wagen'])
        ->and($names('%'))->toBe(['100% Wagen'])
        ->and($names('A_B'))->toBe(['A_B']);
});

it('filters by the admin switch and by operability separately', function () {
    $ok = Vehicle::factory()->create();
    $blocked = Vehicle::factory()->create();
    VehicleDefect::factory()->for($blocked)->blocking()->create();
    $off = Vehicle::factory()->inactive()->create();
    VehicleDefect::factory()->for($off)->blocking()->resolved()->create();
    $u = vh_user('vehicles.view');

    $ids = fn (string $qs) => collect(vh_rows($this, $u, $qs))->pluck('id')->sort()->values()->all();
    expect($ids('active=1'))->toBe([$ok->id, $blocked->id])
        ->and($ids('active=0'))->toBe([$off->id])
        ->and($ids('operable=1'))->toBe([$ok->id])
        ->and($ids('operable=0'))->toBe([$blocked->id, $off->id])
        ->and($ids('active=1&operable=0'))->toBe([$blocked->id])
        ->and($ids('active=x'))->toHaveCount(3);
});

it('sorts by every whitelisted column in both directions', function (string $sort) {
    $lo = Vehicle::factory()->inactive()->create(['name' => 'a lo', 'sort_order' => 1, 'license_plate' => 'A', 'service' => 0]);
    $hi = Vehicle::factory()->create(['name' => 'b hi', 'sort_order' => 2, 'license_plate' => 'B', 'service' => 3]);
    VehicleDefect::factory()->for($hi)->create();
    $u = vh_user('vehicles.view');

    $names = fn (string $dir) => collect(vh_rows($this, $u, "sort={$sort}&dir={$dir}"))->pluck('name')->all();
    expect($names('asc'))->toBe(['a lo', 'b hi'])->and($names('desc'))->toBe(['b hi', 'a lo']);
    expect($lo->id)->toBeInt();
})->with(['priority', 'name', 'license_plate', 'service', 'defects', 'active']);

it('falls back to the default order and survives array-valued parameters', function () {
    Vehicle::factory()->create();
    $u = vh_user('vehicles.view');

    $this->actingAs($u)->get('/vehicles?sort=password&dir=sideways')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('filters.sort', 'priority')->where('filters.dir', 'asc'));
    $this->actingAs($u)->get('/vehicles?search[]=x&sort[]=name&dir[]=desc&active[]=1&operable[]=0&page[]=2')->assertOk();
});

it('paginates 25 per page with the query string and without a query per row', function () {
    $vehicles = Vehicle::factory()->count(27)->create();
    foreach ($vehicles as $v) {
        VehicleDefect::factory()->count(4)->for($v)->create();
    }
    $u = vh_user('vehicles.view');

    DB::enableQueryLog();
    $this->actingAs($u)->get('/vehicles?search=&sort=name')->assertInertia(fn (Assert $p) => $p
        ->component('Vehicles/Index')
        ->has('vehicles.data', 25)->where('vehicles.total', 27)
        ->where('vehicles.next_page_url', fn ($url) => str_contains($url, 'sort=name'))
        ->has('vehicles.data.0.preview_defects', 3));
    expect(count(DB::getQueryLog()))->toBeLessThan(20);
});

// ----- detail -----

it('shows the detail page with activity from the audit module and a constant query count', function () {
    $poi = Poi::factory()->create(['name' => 'Wache 1', 'city' => 'Nord', 'type' => 'Feuerwache']);
    $v = Vehicle::factory()->create(['station_poi_id' => $poi->id, 'allowed_jobs' => ['fire', 'ems'], 'fms_status' => '3']);
    $other = Vehicle::factory()->create();
    $u = vh_user('vehicles.view');
    AuditLogger::log($u, 'Fahrzeug aktualisiert [ID: '.$v->id.']', null, 'Fahrzeuge', true, ['id' => $v->id]);
    AuditLogger::log($u, 'Fahrzeug aktualisiert [ID: '.$other->id.']', null, 'Fahrzeuge', true, ['id' => $other->id]);
    AuditLogger::log($u, 'Fremd', null, 'Mitarbeiter', true, ['id' => $v->id]);
    VehicleDefect::factory()->for($v)->blocking()->create();

    $this->actingAs($u)->get("/vehicles/{$v->id}")->assertInertia(fn (Assert $p) => $p
        ->component('Vehicles/Show')
        ->where('vehicle.station', 'Wache 1 (Nord)')
        ->where('vehicle.allowed_jobs', ['fire', 'ems'])
        ->where('vehicle.fms_label', 'Einsatz übernommen')
        ->where('vehicle.state', 'blocked')
        ->where('vehicle.blocking_defects_count', 1)
        ->has('activity', 1)
        ->where('activity.0.action', 'Fahrzeug aktualisiert [ID: '.$v->id.']')
        ->where('can.manage', false));

    $authors = collect(range(1, 5))->map(fn () => vh_user('vehicles.view'));
    $this->actingAs($u)->get("/vehicles/{$v->id}")->assertOk();
    DB::enableQueryLog();
    $this->actingAs($u)->get("/vehicles/{$v->id}")->assertOk();
    $n = count(DB::getQueryLog());
    foreach ($authors as $author) {
        AuditLogger::log($author, 'Fahrzeug aktualisiert [ID: '.$v->id.']', null, 'Fahrzeuge', true, ['id' => $v->id]);
        VehicleDefect::factory()->for($v)->create();
    }
    DB::flushQueryLog();
    $this->actingAs($u)->get("/vehicles/{$v->id}")->assertOk();
    expect(count(DB::getQueryLog()))->toBe($n);
});

// ----- create / update -----

it('creates a vehicle with cleaned fields and the legacy audit entry', function () {
    $poi = Poi::factory()->create(['type' => 'Rettungswache']);
    $u = vh_user('vehicles.manage');

    $this->actingAs($u)->post('/vehicles', vh_valid([
        'license_plate' => '', 'station_poi_id' => $poi->id, 'allowed_jobs' => " ems , fire\n\n, ems ,",
        'fms_status' => '4',
    ]))->assertRedirect();

    $v = Vehicle::sole();
    expect($v->only(['name', 'type', 'identifier', 'service', 'sort_order', 'is_active', 'license_plate', 'station_poi_id', 'allowed_jobs', 'fms_status', 'tactical_symbol_name']))
        ->toBe(['name' => 'Florian 1/83-1', 'type' => 'RTW', 'identifier' => 'rtw_1', 'service' => 2, 'sort_order' => 3, 'is_active' => true,
            'license_plate' => null, 'station_poi_id' => $poi->id, 'allowed_jobs' => ['ems', 'fire'], 'fms_status' => null, 'tactical_symbol_name' => null]);

    $a = Activity::where('log_name', 'Fahrzeuge')->sole();
    expect($a->description)->toBe('Fahrzeug erstellt [ID: '.$v->id.']')
        ->and($a->getAttribute('details'))->toBe('Name: Florian 1/83-1 | Typ: RTW')
        ->and((bool) $a->getAttribute('is_global'))->toBeTrue()
        ->and($a->properties->all())->toBe(['context' => ['id' => $v->id]]);
});

it('stores empty allowed jobs as null (all jobs) and an unchecked switch as inactive', function () {
    $this->actingAs(vh_user('vehicles.manage'))->post('/vehicles', vh_valid(['allowed_jobs' => ' , ', 'is_active' => false]));

    expect(Vehicle::sole()->only(['allowed_jobs', 'is_active']))->toBe(['allowed_jobs' => null, 'is_active' => false]);
});

it('validates the form', function () {
    foreach ([
        'name missing' => [vh_valid(['name' => '']), 'name'],
        'type missing' => [vh_valid(['type' => ' ']), 'type'],
        'identifier missing' => [vh_valid(['identifier' => null]), 'identifier'],
        'name array' => [vh_valid(['name' => ['x']]), 'name'],
        'service 4' => [vh_valid(['service' => 4]), 'service'],
        'service text' => [vh_valid(['service' => 'fire']), 'service'],
        'sort order text' => [vh_valid(['sort_order' => 'x']), 'sort_order'],
        'sort order overflow' => [vh_valid(['sort_order' => '99999999999']), 'sort_order'],
        'jobs too long' => [vh_valid(['allowed_jobs' => str_repeat('a', 501)]), 'allowed_jobs'],
        'jobs array' => [vh_valid(['allowed_jobs' => ['a']]), 'allowed_jobs'],
        'name too long' => [vh_valid(['name' => str_repeat('a', 256)]), 'name'],
    ] as [$input, $field]) {
        $this->actingAs(vh_user('vehicles.manage'))->post('/vehicles', $input)->assertSessionHasErrors($field);
        expect(Vehicle::count())->toBe(0);
    }
});

it('accepts only active stations as station', function () {
    $u = vh_user('vehicles.manage');
    $inactive = Poi::factory()->create(['type' => 'Feuerwache', 'is_active' => false]);
    $hospital = Poi::factory()->create(['type' => 'Krankenhaus']);

    foreach ([$inactive->id, $hospital->id, 999, 'x'] as $id) {
        $this->actingAs($u)->post('/vehicles', vh_valid(['station_poi_id' => $id]))->assertSessionHasErrors('station_poi_id');
    }
    expect(Vehicle::count())->toBe(0);
});

it('offers only active stations in the form, labelled "Name (City)"', function () {
    Poi::factory()->create(['name' => 'RW Süd', 'city' => 'Süd', 'type' => 'Rettungswache']);
    Poi::factory()->create(['name' => 'Alt', 'type' => 'Rettungswache', 'is_active' => false]);
    Poi::factory()->create(['name' => 'Klinik', 'type' => 'Krankenhaus']);

    $this->actingAs(vh_user('vehicles.manage'))->get('/vehicles/create')->assertInertia(fn (Assert $p) => $p
        ->component('Vehicles/Form')->where('vehicle', null)
        ->has('stations', 1)->where('stations.0.label', 'RW Süd (Süd)'));
});

it('updates a vehicle without touching the FMS status and logs the legacy entry', function () {
    $v = Vehicle::factory()->create(['fms_status' => '2', 'allowed_jobs' => ['ems']]);
    $u = vh_user('vehicles.manage', 'vehicles.view');

    $this->actingAs($u)->get("/vehicles/{$v->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->component('Vehicles/Form')->where('vehicle.id', $v->id)->where('vehicle.allowed_jobs', 'ems'));
    $this->actingAs($u)->put("/vehicles/{$v->id}", vh_valid(['name' => 'Neu', 'allowed_jobs' => '', 'is_active' => '0']))
        ->assertRedirect("/vehicles/{$v->id}");

    expect($v->fresh()->only(['name', 'allowed_jobs', 'is_active', 'fms_status']))
        ->toBe(['name' => 'Neu', 'allowed_jobs' => null, 'is_active' => false, 'fms_status' => '2']);
    $a = Activity::where('log_name', 'Fahrzeuge')->sole();
    expect($a->description)->toBe('Fahrzeug aktualisiert [ID: '.$v->id.']')->and($a->properties['context'])->toBe(['id' => $v->id]);
});

it('deletes a vehicle with its defects and logs it', function () {
    $v = Vehicle::factory()->create();
    VehicleDefect::factory()->for($v)->create();

    $this->actingAs(vh_user('vehicles.manage', 'vehicles.view'))->delete("/vehicles/{$v->id}")->assertRedirect('/vehicles');

    expect(Vehicle::count())->toBe(0)->and(VehicleDefect::count())->toBe(0);
    expect(Activity::where('log_name', 'Fahrzeuge')->sole()->description)->toBe('Fahrzeug gelöscht [ID: '.$v->id.']');
});

// ----- legacy URLs -----

it('redirects the legacy vehicle pages permanently and keeps the query', function (string $from, string $to) {
    $r = $this->get($from);
    $r->assertStatus(301);
    expect($r->headers->get('Location'))->toBe($to);
})->with([
    ['/settings/vehicles/vehicles/index', '/vehicles'],
    ['/settings/vehicles/vehicles/index.php?q=hlf&sort=kennzeichen&dir=desc&active=1', '/vehicles?search=hlf&sort=license_plate&dir=desc&active=1'],
    ['/settings/vehicles/vehicles/create', '/vehicles/create'],
    ['/settings/vehicles/vehicles/12', '/vehicles/12'],
    ['/settings/vehicles/vehicles/12/edit', '/vehicles/12/edit'],
    ['/settings/fahrzeuge/fahrzeuge/index', '/vehicles'],
    ['/settings/fahrzeuge/fahrzeuge/7', '/vehicles/7'],
]);

it('drops the legacy preview and ignores ids that are not ids', function () {
    $this->actingAs(vh_user('admin'));
    $this->get('/settings/vehicles/vehicles/12/preview')->assertNotFound();
    $this->get('/settings/vehicles/vehicles/0')->assertNotFound();
    $this->get('/settings/vehicles/vehicles/12abc')->assertNotFound();
});

// ----- fix round 1 -----

it('keeps a station that was deactivated after the vehicle was placed there', function () {
    $u = vh_user('vehicles.manage', 'vehicles.view');
    $station = Poi::factory()->create(['name' => 'FW Alt', 'city' => 'Nord', 'type' => 'Feuerwache']);
    $this->actingAs($u)->post('/vehicles', vh_valid(['station_poi_id' => $station->id]));
    $v = Vehicle::sole();
    $station->update(['is_active' => false]);
    $otherInactive = Poi::factory()->create(['type' => 'Feuerwache', 'is_active' => false]);

    $this->actingAs($u)->get("/vehicles/{$v->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->has('stations', 1)->where('stations.0.id', $station->id)->where('stations.0.label', 'FW Alt (Nord) (inaktiv)'));
    $this->actingAs($u)->put("/vehicles/{$v->id}", vh_valid(['station_poi_id' => $station->id]))
        ->assertSessionHasNoErrors()->assertRedirect("/vehicles/{$v->id}");
    $this->actingAs($u)->put("/vehicles/{$v->id}", vh_valid(['station_poi_id' => $otherInactive->id]))->assertSessionHasErrors('station_poi_id');
    $this->actingAs($u)->post('/vehicles', vh_valid(['station_poi_id' => $station->id]))->assertSessionHasErrors('station_poi_id');
    $this->actingAs($u)->get('/vehicles/create')->assertInertia(fn (Assert $p) => $p->has('stations', 0));
    expect($v->fresh()->station_poi_id)->toBe($station->id);
});

it('sends manage-only accounts back to the form or home instead of a 403 page', function () {
    $u = vh_user('vehicles.manage');

    $this->actingAs($u)->post('/vehicles', vh_valid())->assertRedirect('/vehicles/'.Vehicle::sole()->id.'/edit')
        ->assertSessionHas('status', 'vehicles.saved');
    $v = Vehicle::sole();
    $this->actingAs($u)->put("/vehicles/{$v->id}", vh_valid())->assertRedirect("/vehicles/{$v->id}/edit");
    $this->actingAs($u)->get("/vehicles/{$v->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->where('canView', false)->where('flash.status', 'vehicles.saved'));
    $this->actingAs($u)->delete("/vehicles/{$v->id}")->assertRedirect('/')->assertSessionHas('status', 'vehicles.deleted');

    $both = vh_user('vehicles.manage', 'vehicles.view');
    $w = Vehicle::factory()->create();
    $this->actingAs($both)->get("/vehicles/{$w->id}/edit")->assertInertia(fn (Assert $p) => $p->where('canView', true));
    $this->actingAs($both)->delete("/vehicles/{$w->id}")->assertRedirect('/vehicles');
});

it('cleans the job list: unicode separators, NBSP and control characters, case-insensitive duplicates', function () {
    $this->actingAs(vh_user('vehicles.manage'))->post('/vehicles', vh_valid([
        'name' => "Flo\0rian", 'identifier' => "id\0", 'type' => "R\0TW", 'license_plate' => "HH\0-1",
        'allowed_jobs' => "\u{00A0}ems\u{00A0}, EMS ,fire\0\n\x01police\x02, Fire",
    ]))->assertSessionHasNoErrors();

    expect(Vehicle::sole()->only(['name', 'identifier', 'type', 'license_plate', 'allowed_jobs']))
        ->toBe(['name' => 'Florian', 'identifier' => 'id', 'type' => 'RTW', 'license_plate' => 'HH-1', 'allowed_jobs' => ['ems', 'fire', 'police']]);
});

it('rejects job lists with too long or too many entries', function (string $jobs) {
    $this->actingAs(vh_user('vehicles.manage'))->post('/vehicles', vh_valid(['allowed_jobs' => $jobs]))->assertSessionHasErrors('allowed_jobs');
    expect(Vehicle::count())->toBe(0);
})->with([
    'item over 100' => [str_repeat('a', 101)],
    '51 items' => [implode(',', array_map(fn ($i) => 'j'.$i, range(1, 51)))],
    'invalid utf-8' => ["ems,\xC3("],
]);

it('shares the FMS labels as an object keyed by code', function () {
    $labels = $this->actingAs(vh_user('vehicles.view'))->get('/vehicles')->viewData('page')['props']['fmsLabels'];
    expect(json_encode($labels, JSON_UNESCAPED_UNICODE))->toStartWith('{"0":"Dringender Sprechwunsch"');
});

it('refuses invalid UTF-8 in every text field with 422 instead of a database error', function (string $field) {
    $this->actingAs(vh_user('vehicles.manage', 'vehicles.view'))->withHeader('Accept', 'application/json')->post('/vehicles', vh_valid([$field => "ab\xffcd"]))
        ->assertStatus(422)->assertJsonValidationErrors($field);
})->with(['name', 'type', 'identifier', 'license_plate']);
