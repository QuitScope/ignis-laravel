<?php

use App\Events\VehicleDefectReported;
use App\Events\VehicleDefectResolved;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Models\VehicleDefectEvent;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function vd_user(string ...$perms): User
{
    $role = Role::create(['name' => 'D'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function vd_events(VehicleDefect $d): array
{
    return VehicleDefectEvent::where('vehicle_defect_id', $d->id)->orderBy('id')->get()
        ->map(fn (VehicleDefectEvent $e) => [$e->action, $e->details])->all();
}

// ----- rights -----

it('guards every route: view reads and reports, manage with view changes, only admin deletes', function () {
    $v = Vehicle::factory()->create();
    $viewer = vd_user('vehicles.view');
    $manager = vd_user('vehicles.manage');
    $boss = vd_user('vehicles.view', 'vehicles.manage');
    $nobody = vd_user('audit.view');
    $admin = vd_user('admin');
    $d = VehicleDefect::factory()->for($v)->create();

    $routes = [['get', '/vehicles/defects'], ['get', '/vehicles/defects/create'], ['post', "/vehicles/{$v->id}/defects"],
        ['get', "/vehicles/defects/{$d->id}"], ['patch', "/vehicles/defects/{$d->id}"], ['post', "/vehicles/defects/{$d->id}/resolve"],
        ['delete', "/vehicles/defects/{$d->id}"]];
    foreach ($routes as [$method, $url]) {
        $this->{$method}($url)->assertRedirect('/login');
    }
    foreach ($routes as [$method, $url]) {
        $this->actingAs($nobody)->{$method}($url)->assertForbidden();
    }

    $this->actingAs($viewer)->get('/vehicles/defects')->assertOk();
    $this->actingAs($viewer)->getJson("/vehicles/defects/{$d->id}")->assertOk();
    $this->actingAs($viewer)->get('/vehicles/defects/create')->assertOk();
    $this->actingAs($viewer)->post("/vehicles/{$v->id}/defects", ['title' => 'Licht'])->assertRedirect();
    $this->actingAs($viewer)->patch("/vehicles/defects/{$d->id}", ['status' => 'in_progress'])->assertForbidden();
    $this->actingAs($viewer)->post("/vehicles/defects/{$d->id}/resolve")->assertForbidden();
    $this->actingAs($viewer)->delete("/vehicles/defects/{$d->id}")->assertForbidden();

    $this->actingAs($manager)->get('/vehicles/defects')->assertForbidden();
    $this->actingAs($manager)->getJson("/vehicles/defects/{$d->id}")->assertForbidden();
    $this->actingAs($manager)->get('/vehicles/defects/create')->assertOk();
    $this->actingAs($manager)->post("/vehicles/{$v->id}/defects", ['title' => 'Licht'])->assertRedirect();
    $this->actingAs($manager)->patch("/vehicles/defects/{$d->id}", ['status' => 'in_progress', 'from_status' => 'open'])->assertForbidden();
    $this->actingAs($manager)->post("/vehicles/defects/{$d->id}/resolve")->assertForbidden();
    $this->actingAs($manager)->delete("/vehicles/defects/{$d->id}")->assertForbidden();

    $this->actingAs($boss)->patch("/vehicles/defects/{$d->id}", ['status' => 'in_progress', 'from_status' => 'open'])->assertRedirect();
    $this->actingAs($boss)->post("/vehicles/defects/{$d->id}/resolve")->assertRedirect();
    $this->actingAs($boss)->delete("/vehicles/defects/{$d->id}")->assertForbidden();
    expect($d->fresh()->status)->toBe('resolved');

    $this->actingAs($admin)->delete("/vehicles/defects/{$d->id}")->assertRedirect();
    expect(VehicleDefect::find($d->id))->toBeNull();

    $super = User::factory()->superAdmin()->create();
    $e = VehicleDefect::factory()->for($v)->create();
    $this->actingAs($super)->get('/vehicles/defects')->assertOk();
    $this->actingAs($super)->getJson("/vehicles/defects/{$e->id}")->assertOk();
    $this->actingAs($super)->post("/vehicles/{$v->id}/defects", ['title' => 'Licht'])->assertRedirect();
    $this->actingAs($super)->patch("/vehicles/defects/{$e->id}", ['status' => 'deferred', 'from_status' => 'open'])->assertRedirect();
    $this->actingAs($super)->post("/vehicles/defects/{$e->id}/resolve")->assertRedirect();
    $this->actingAs($super)->delete("/vehicles/defects/{$e->id}")->assertRedirect();
    expect(VehicleDefect::find($e->id))->toBeNull();
});

it('answers 404 for unknown, malformed and overflowing ids', function () {
    $admin = vd_user('admin');
    foreach (['/vehicles/defects/999', '/vehicles/defects/abc', '/vehicles/defects/99999999999999999999'] as $url) {
        $this->actingAs($admin)->get($url)->assertNotFound();
    }
    $this->actingAs($admin)->post('/vehicles/999/defects', ['title' => 'x'])->assertNotFound();
    $this->actingAs($admin)->post('/vehicles/99999999999999999999/defects', ['title' => 'x'])->assertNotFound();
});

// ----- reporting -----

it('reports a blocking defect: is_active stays, operability goes, history and audit are written', function () {
    $v = Vehicle::factory()->create(['is_active' => true]);
    $u = vd_user('vehicles.view');

    $this->actingAs($u)->post("/vehicles/{$v->id}/defects", [
        'title' => '  Blaulicht defekt ', 'description' => 'links', 'category' => 'lighting', 'is_operable' => '0',
    ])->assertRedirect("/vehicles/defects?vehicle={$v->id}")->assertSessionHas('status', 'vehicles.defects.reported_blocking');

    $d = VehicleDefect::sole();
    expect($d->title)->toBe('Blaulicht defekt')->and($d->category)->toBe('lighting')->and($d->is_operable)->toBeFalse()
        ->and($d->status)->toBe('open')->and($d->reported_by)->toBe($u->id)->and($d->reporter_name)->toBeNull();
    $v->refresh();
    expect($v->is_active)->toBeTrue()->and($v->is_operable)->toBeFalse();
    expect(vd_events($d))->toBe([['created', 'Defekt gemeldet: Blaulicht defekt']]);

    $a = Activity::where('log_name', 'Fahrzeuge')->sole();
    expect($a->description)->toBe("Defekt gemeldet [ID: {$d->id}]")
        ->and($a->getAttribute('details'))->toBe("Fahrzeug-ID: {$v->id} | Blaulicht defekt")
        ->and($a->getAttribute('is_global'))->toBeTruthy()
        ->and($a->properties['context'])->toBe(['id' => $v->id, 'defect_id' => $d->id]);
})->group('mariadb');

it('stores unknown categories as other and defaults to operable', function () {
    $v = Vehicle::factory()->create();
    $u = vd_user('vehicles.view');
    $this->actingAs($u)->post("/vehicles/{$v->id}/defects", ['title' => 'A', 'category' => 'warp_drive'])
        ->assertSessionHas('status', 'vehicles.defects.reported');
    $this->actingAs($u)->post("/vehicles/{$v->id}/defects", ['title' => 'B', 'category' => ['x']]);

    expect(VehicleDefect::orderBy('id')->pluck('category')->all())->toBe(['other', 'other']);
    expect(VehicleDefect::where('is_operable', false)->count())->toBe(0);
    expect($v->fresh()->is_operable)->toBeTrue();
});

it('validates title, description and operable flag', function (array $input, string $field) {
    $v = Vehicle::factory()->create();
    $this->actingAs(vd_user('vehicles.view'))->post("/vehicles/{$v->id}/defects", $input)->assertSessionHasErrors($field);
    expect(VehicleDefect::count())->toBe(0);
})->with([
    'missing title' => [[], 'title'],
    'blank title' => [['title' => '   '], 'title'],
    'title as array' => [['title' => ['a']], 'title'],
    'long title' => [['title' => str_repeat('ä', 201)], 'title'],
    'long description' => [['title' => 'a', 'description' => str_repeat('x', 5001)], 'description'],
    'invalid utf-8' => [['title' => "a\xC3\x28"], 'title'],
    'operable as text' => [['title' => 'a', 'is_operable' => 'vielleicht'], 'is_operable'],
]);

it('accepts the limits exactly', function () {
    $v = Vehicle::factory()->create();
    $this->actingAs(vd_user('vehicles.view'))->post("/vehicles/{$v->id}/defects", ['title' => str_repeat('ä', 200), 'description' => str_repeat('x', 5000)])
        ->assertSessionHasNoErrors();
    expect(mb_strlen(VehicleDefect::sole()->title))->toBe(200);
});

it('redirects a reporter without the view right back to the form', function () {
    $v = Vehicle::factory()->create();
    $this->actingAs(vd_user('vehicles.manage'))->post("/vehicles/{$v->id}/defects", ['title' => 'a'])
        ->assertRedirect("/vehicles/defects/create?vehicle={$v->id}");
});

it('fires VehicleDefectReported exactly once, after the commit', function () {
    $seen = [];
    Event::listen(VehicleDefectReported::class, function (VehicleDefectReported $e) use (&$seen) {
        $seen[] = [$e->defect->id, VehicleDefect::whereKey($e->defect->id)->exists()];
    });
    $v = Vehicle::factory()->create();

    $this->actingAs(vd_user('vehicles.view'))->post("/vehicles/{$v->id}/defects", ['title' => 'a']);

    expect($seen)->toBe([[VehicleDefect::sole()->id, true]]);
});

// ----- list -----

it('lists defects in status order with blocking first, filters, stats and the last event', function () {
    $a = Vehicle::factory()->create(['name' => 'RTW 1']);
    $b = Vehicle::factory()->create(['name' => 'HLF 2']);
    $resolved = VehicleDefect::factory()->for($a)->resolved()->create(['title' => 'resolved']);
    $deferred = VehicleDefect::factory()->for($a)->create(['title' => 'deferred', 'status' => 'deferred']);
    $progress = VehicleDefect::factory()->for($b)->create(['title' => 'progress', 'status' => 'in_progress']);
    $openInfo = VehicleDefect::factory()->for($a)->create(['title' => 'open info', 'created_at' => now()->subDay()]);
    $openOld = VehicleDefect::factory()->for($a)->create(['title' => 'open newer', 'created_at' => now()]);
    $openBlocking = VehicleDefect::factory()->for($b)->blocking()->create(['title' => 'open blocking', 'created_at' => now()->subWeek()]);
    VehicleDefectEvent::create(['vehicle_defect_id' => $progress->id, 'action' => 'created', 'details' => 'erst']);
    VehicleDefectEvent::create(['vehicle_defect_id' => $progress->id, 'action' => 'updated', 'details' => 'zuletzt']);
    $u = vd_user('vehicles.view');

    $page = fn (string $qs = '') => $this->actingAs($u)->get('/vehicles/defects'.$qs)->viewData('page')['props'];

    $all = $page();
    expect(array_column($all['defects']['data'], 'title'))->toBe(['open blocking', 'open newer', 'open info', 'progress', 'deferred', 'resolved']);
    $row = $all['defects']['data'][3];
    expect($row['vehicle'])->toBe(['id' => $b->id, 'name' => 'HLF 2'])->and($row['last_event']['details'])->toBe('zuletzt');
    expect($all['defects']['data'][0]['is_blocking'])->toBeTrue();
    expect($all['stats'])->toBe(['open' => 3, 'in_progress' => 1, 'deferred' => 1, 'resolved' => 1, 'blocking' => 1, 'total' => 6]);

    expect(array_column($page("?vehicle={$b->id}")['defects']['data'], 'title'))->toBe(['open blocking', 'progress']);
    expect($page("?vehicle={$b->id}")['stats']['total'])->toBe(2);
    expect(array_column($page('?status=deferred')['defects']['data'], 'title'))->toBe(['deferred']);
    expect($page("?vehicle={$a->id}&status=open")['filters'])->toBe(['vehicle' => $a->id, 'status' => 'open']);
});

it('ignores array, unknown and overflowing filter values', function (string $qs) {
    VehicleDefect::factory()->count(2)->create();
    $props = $this->actingAs(vd_user('vehicles.view'))->get('/vehicles/defects'.$qs)->assertOk()->viewData('page')['props'];
    expect($props['defects']['total'])->toBe(2)->and($props['filters'])->toBe(['vehicle' => null, 'status' => '']);
})->with(['?vehicle[]=1&status[]=open', '?vehicle=abc&status=kaputt', '?vehicle=99999999999999999999', '?vehicle=-1&page[]=2']);

it('paginates by 25 and keeps the filters in the links', function () {
    $v = Vehicle::factory()->create();
    VehicleDefect::factory()->for($v)->count(26)->create();
    $p = $this->actingAs(vd_user('vehicles.view'))->get("/vehicles/defects?vehicle={$v->id}")->viewData('page')['props']['defects'];
    expect($p['data'])->toHaveCount(25)->and($p['next_page_url'])->toContain("vehicle={$v->id}");
});

it('offers only active accounts for assignment and only to managers', function () {
    $active = User::factory()->create(['username' => 'aktiv', 'name' => null]);
    User::factory()->create(['username' => 'weg', 'name' => null, 'is_active' => false]);
    $viewer = vd_user('vehicles.view');
    $boss = vd_user('vehicles.view', 'vehicles.manage');

    expect($this->actingAs($viewer)->get('/vehicles/defects')->viewData('page')['props']['users'])->toBe([]);
    $names = array_column($this->actingAs($boss)->get('/vehicles/defects')->viewData('page')['props']['users'], 'name');
    expect($names)->toContain('aktiv')->not->toContain('weg');
});

it('renders the create page with the vehicle preselected', function () {
    $v = Vehicle::factory()->create();
    $this->actingAs(vd_user('vehicles.view'))->get("/vehicles/defects/create?vehicle={$v->id}")
        ->assertInertia(fn (Assert $p) => $p->component('Vehicles/Defects/Create')->where('selected', $v->id)->has('vehicles', 1)->has('categories', 14));
    $this->actingAs(vd_user('vehicles.view'))->get('/vehicles/defects/create?vehicle[]=1')
        ->assertInertia(fn (Assert $p) => $p->where('selected', null));
});

it('shows the vehicle defects tab data on the vehicle page', function () {
    $v = Vehicle::factory()->create();
    VehicleDefect::factory()->for($v)->create(['title' => 'mine']);
    VehicleDefect::factory()->create(['title' => 'other']);
    $this->actingAs(vd_user('vehicles.view'))->get("/vehicles/{$v->id}")
        ->assertInertia(fn (Assert $p) => $p->has('defects', 1)->where('defects.0.title', 'mine')->where('can.createDefect', true));
});

// ----- detail -----

it('returns the detail with its history as JSON', function () {
    $reporter = User::factory()->create(['username' => 'melder']);
    $reporter->personnel()->associate(Personnel::factory()->create(['full_name' => 'Max Muster']))->save();
    $d = VehicleDefect::factory()->create(['title' => 'X', 'reported_by' => $reporter->id, 'description' => 'lang']);
    VehicleDefectEvent::create(['vehicle_defect_id' => $d->id, 'user_id' => $reporter->id, 'action' => 'created', 'details' => 'Defekt gemeldet: X']);

    $this->actingAs(vd_user('vehicles.view'))->getJson("/vehicles/defects/{$d->id}")->assertOk()
        ->assertJsonPath('defect.title', 'X')
        ->assertJsonPath('defect.description', 'lang')
        ->assertJsonPath('defect.reporter', 'Max Muster')
        ->assertJsonPath('defect.events.0.details', 'Defekt gemeldet: X')
        ->assertJsonPath('defect.events.0.user', 'Max Muster');
});

// ----- update -----

it('changes the status with the legacy history texts', function () {
    $d = VehicleDefect::factory()->create();
    $u = vd_user('vehicles.view', 'vehicles.manage');

    $this->actingAs($u)->patch("/vehicles/defects/{$d->id}", ['status' => 'in_progress', 'from_status' => 'open', 'status_note' => 'Werkstatt'])->assertRedirect();
    $this->actingAs($u)->patch("/vehicles/defects/{$d->id}", ['status' => 'deferred', 'from_status' => 'in_progress']);
    $this->actingAs($u)->patch("/vehicles/defects/{$d->id}", ['status' => 'deferred', 'from_status' => 'deferred']);

    expect($d->fresh()->status)->toBe('deferred');
    expect(vd_events($d))->toBe([
        ['updated', 'Status geändert: Offen → In Bearbeitung | Werkstatt'],
        ['updated', 'Status geändert: In Bearbeitung → Aufgeschoben'],
    ]);
});

it('refuses resolved and unknown statuses on PATCH', function (mixed $status) {
    $d = VehicleDefect::factory()->create();
    $this->actingAs(vd_user('vehicles.view', 'vehicles.manage'))->patch("/vehicles/defects/{$d->id}", ['status' => $status, 'from_status' => 'open'])->assertSessionHasErrors('status');
    expect($d->fresh()->status)->toBe('open');
})->with(['resolved', 'kaputt', [['open']]]);

it('reopens a resolved defect and clears the resolution', function () {
    $d = VehicleDefect::factory()->resolved()->create(['resolved_by' => vd_user()->id, 'resolution_note' => 'gut']);
    $this->actingAs(vd_user('vehicles.view', 'vehicles.manage'))->patch("/vehicles/defects/{$d->id}", ['status' => 'open', 'from_status' => 'resolved']);

    $d->refresh();
    expect($d->status)->toBe('open')->and($d->resolved_at)->toBeNull()->and($d->resolved_by)->toBeNull()->and($d->resolution_note)->toBeNull();
    expect(vd_events($d))->toBe([['updated', 'Status geändert: Gelöst → Offen']]);
});

it('assigns only active accounts and logs assignment and removal', function () {
    $d = VehicleDefect::factory()->create();
    $u = vd_user('vehicles.view', 'vehicles.manage');
    $worker = User::factory()->create(['username' => 'schrauber', 'name' => null]);
    $gone = User::factory()->create(['is_active' => false]);

    $this->actingAs($u)->patch("/vehicles/defects/{$d->id}", ['assigned_to' => $gone->id])->assertSessionHasErrors('assigned_to');
    $this->actingAs($u)->patch("/vehicles/defects/{$d->id}", ['assigned_to' => 99999999999999999999])->assertSessionHasErrors('assigned_to');
    $this->actingAs($u)->patch("/vehicles/defects/{$d->id}", ['assigned_to' => ['1']])->assertSessionHasErrors('assigned_to');
    expect($d->fresh()->assigned_to)->toBeNull();

    $this->actingAs($u)->patch("/vehicles/defects/{$d->id}", ['assigned_to' => $worker->id])->assertSessionHasNoErrors();
    expect($d->fresh()->assigned_to)->toBe($worker->id);
    $this->actingAs($u)->patch("/vehicles/defects/{$d->id}", ['assigned_to' => '']);
    expect($d->fresh()->assigned_to)->toBeNull();

    expect(vd_events($d))->toBe([['updated', 'Zugewiesen an: schrauber'], ['updated', 'Zuweisung entfernt']]);
});

// ----- resolve -----

it('resolving the last blocking defect makes the vehicle operable again without touching is_active', function () {
    $on = Vehicle::factory()->create(['is_active' => true]);
    $off = Vehicle::factory()->create(['is_active' => false]);
    $dOn = VehicleDefect::factory()->for($on)->blocking()->create();
    $dOff = VehicleDefect::factory()->for($off)->blocking()->create();
    $u = vd_user('vehicles.view', 'vehicles.manage');
    $resolved = [];
    Event::listen(VehicleDefectResolved::class, function (VehicleDefectResolved $e) use (&$resolved) {
        $resolved[] = $e->defect->id;
    });

    expect($on->fresh()->is_operable)->toBeFalse();
    $this->actingAs($u)->post("/vehicles/defects/{$dOn->id}/resolve", ['resolution_note' => 'getauscht'])->assertRedirect();
    $this->actingAs($u)->post("/vehicles/defects/{$dOff->id}/resolve");

    $dOn->refresh();
    expect($dOn->status)->toBe('resolved')->and($dOn->resolved_by)->toBe($u->id)->and($dOn->resolved_at)->not->toBeNull()
        ->and($dOn->resolution_note)->toBe('getauscht');
    expect(vd_events($dOn))->toBe([['resolved', 'Als gelöst markiert: getauscht']]);
    expect(vd_events($dOff))->toBe([['resolved', 'Als gelöst markiert']]);
    expect($on->fresh()->is_active)->toBeTrue()->and($on->fresh()->is_operable)->toBeTrue();
    expect($off->fresh()->is_active)->toBeFalse()->and($off->fresh()->is_operable)->toBeFalse();
    expect($resolved)->toBe([$dOn->id, $dOff->id]);
})->group('mariadb');

it('refuses a second resolution: validation error on the page, 409 as JSON, nothing changes', function () {
    $d = VehicleDefect::factory()->create();
    $first = vd_user('vehicles.view', 'vehicles.manage');
    $second = vd_user('vehicles.view', 'vehicles.manage');

    $this->actingAs($first)->post("/vehicles/defects/{$d->id}/resolve", ['resolution_note' => 'eins']);
    $this->actingAs($second)->from('/vehicles/defects')->post("/vehicles/defects/{$d->id}/resolve", ['resolution_note' => 'zwei'])
        ->assertRedirect('/vehicles/defects')->assertSessionHasErrors(['resolution_note' => trans('vehicles.defects.error.already_resolved')]);
    $this->actingAs($second)->postJson("/vehicles/defects/{$d->id}/resolve", ['resolution_note' => 'drei'])->assertStatus(409);

    $d->refresh();
    expect($d->resolved_by)->toBe($first->id)->and($d->resolution_note)->toBe('eins');
    expect(VehicleDefectEvent::where('action', 'resolved')->count())->toBe(1);
})->group('mariadb');

it('decides a resolution by the conditional update, not by what the request saw', function () {
    $d = VehicleDefect::factory()->create();
    $u = vd_user('vehicles.view', 'vehicles.manage');
    DB::table('vehicle_defects')->where('id', $d->id)->update(['status' => 'resolved', 'resolution_note' => 'parallel']);

    $this->actingAs($u)->postJson("/vehicles/defects/{$d->id}/resolve", ['resolution_note' => 'spät'])->assertStatus(409);
    expect($d->fresh()->resolution_note)->toBe('parallel');
})->group('mariadb');

// ----- fix round 1 -----

it('refuses a status change from a stale dialog instead of reopening a defect resolved meanwhile', function () {
    $d = VehicleDefect::factory()->create();
    $resolver = vd_user('vehicles.view', 'vehicles.manage');
    $stale = vd_user('vehicles.view', 'vehicles.manage');

    $this->actingAs($resolver)->post("/vehicles/defects/{$d->id}/resolve", ['resolution_note' => 'fertig']);
    $this->actingAs($stale)->from('/vehicles/defects')->patch("/vehicles/defects/{$d->id}", ['status' => 'in_progress', 'from_status' => 'open'])
        ->assertRedirect('/vehicles/defects')->assertSessionHasErrors(['status' => trans('vehicles.defects.error.changed')]);
    $this->actingAs($stale)->patchJson("/vehicles/defects/{$d->id}", ['status' => 'in_progress', 'from_status' => 'open'])->assertStatus(409);

    $d->refresh();
    expect($d->status)->toBe('resolved')->and($d->resolution_note)->toBe('fertig')->and($d->resolved_by)->toBe($resolver->id);
    expect(VehicleDefectEvent::where('action', 'updated')->count())->toBe(0);
});

it('requires the status the dialog showed with every status change', function (mixed $from) {
    $d = VehicleDefect::factory()->create();
    $input = ['status' => 'in_progress'] + ($from === null ? [] : ['from_status' => $from]);
    $this->actingAs(vd_user('vehicles.view', 'vehicles.manage'))->patch("/vehicles/defects/{$d->id}", $input)->assertSessionHasErrors('from_status');
    expect($d->fresh()->status)->toBe('open');
})->with([null, 'kaputt', [['open']]]);

it('keeps a resolved defect resolved when only the assignment changes', function () {
    $d = VehicleDefect::factory()->resolved()->create(['resolution_note' => 'gut']);
    $worker = User::factory()->create(['username' => 'schrauber', 'name' => null]);

    $this->actingAs(vd_user('vehicles.view', 'vehicles.manage'))->patch("/vehicles/defects/{$d->id}", ['assigned_to' => $worker->id])
        ->assertSessionHasNoErrors();

    $d->refresh();
    expect($d->status)->toBe('resolved')->and($d->resolution_note)->toBe('gut')->and($d->resolved_at)->not->toBeNull()
        ->and($d->assigned_to)->toBe($worker->id);
});

it('ignores forged columns in the report body', function () {
    $v = Vehicle::factory()->create(['is_active' => true]);
    $other = Vehicle::factory()->create();
    $u = vd_user('vehicles.view');

    $this->actingAs($u)->post("/vehicles/{$v->id}/defects", [
        'title' => 'A', 'vehicle_id' => $other->id, 'reported_by' => vd_user()->id, 'status' => 'resolved', 'is_active' => '0',
        'resolved_at' => '2020-01-01', 'assigned_to' => $u->id, 'reporter_name' => 'Fake',
    ])->assertSessionHasNoErrors();

    $d = VehicleDefect::sole();
    expect($d->vehicle_id)->toBe($v->id)->and($d->reported_by)->toBe($u->id)->and($d->status)->toBe('open')
        ->and($d->resolved_at)->toBeNull()->and($d->assigned_to)->toBeNull()->and($d->reporter_name)->toBeNull();
    expect($v->fresh()->is_active)->toBeTrue();
});

it('shows the report in the vehicle activity tab', function () {
    $v = Vehicle::factory()->create();
    $u = vd_user('vehicles.view');
    $this->actingAs($u)->post("/vehicles/{$v->id}/defects", ['title' => 'Spiegel']);
    $id = VehicleDefect::sole()->id;

    $this->actingAs($u)->get("/vehicles/{$v->id}")
        ->assertInertia(fn (Assert $p) => $p->where('activity.0.action', "Defekt gemeldet [ID: {$id}]")
            ->where('activity.0.details', "Fahrzeug-ID: {$v->id} | Spiegel"));
});

it('dispatches the reported and resolved events', function () {
    Event::fake([VehicleDefectReported::class, VehicleDefectResolved::class]);
    $v = Vehicle::factory()->create();
    $u = vd_user('vehicles.view', 'vehicles.manage');

    $this->actingAs($u)->post("/vehicles/{$v->id}/defects", ['title' => 'A']);
    $d = VehicleDefect::sole();
    $this->actingAs($u)->post("/vehicles/defects/{$d->id}/resolve");

    Event::assertDispatchedTimes(VehicleDefectReported::class, 1);
    Event::assertDispatched(VehicleDefectReported::class, fn (VehicleDefectReported $e) => $e->defect->is($d));
    Event::assertDispatchedTimes(VehicleDefectResolved::class, 1);
    Event::assertDispatched(VehicleDefectResolved::class, fn (VehicleDefectResolved $e) => $e->defect->is($d) && $e->defect->status === 'resolved');
    expect(new VehicleDefectReported($d))->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and(new VehicleDefectResolved($d))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

it('validates the resolution note', function () {
    $d = VehicleDefect::factory()->create();
    $this->actingAs(vd_user('vehicles.view', 'vehicles.manage'))->post("/vehicles/defects/{$d->id}/resolve", ['resolution_note' => ['x']])
        ->assertSessionHasErrors('resolution_note');
    expect($d->fresh()->status)->toBe('open');
});

// ----- delete -----

it('deletes a defect with its history', function () {
    $d = VehicleDefect::factory()->create();
    VehicleDefectEvent::create(['vehicle_defect_id' => $d->id, 'action' => 'created']);
    $this->actingAs(vd_user('admin'))->delete("/vehicles/defects/{$d->id}")->assertRedirect();
    expect(VehicleDefect::count())->toBe(0)->and(VehicleDefectEvent::count())->toBe(0);
});

// ----- legacy URLs -----

it('redirects the legacy defect pages permanently and keeps the query', function (string $from, string $to) {
    $r = $this->get($from);
    $r->assertStatus(301);
    expect($r->headers->get('Location'))->toBe($to);
})->with([
    ['/settings/vehicles/defects/index?vehicle=3&status=open', '/vehicles/defects?vehicle=3&status=open'],
    ['/settings/vehicles/defects/index.php?vehicle=3', '/vehicles/defects?vehicle=3'],
    ['/settings/vehicles/defects/create?vehicle=3', '/vehicles/defects/create?vehicle=3'],
    ['/settings/fahrzeuge/defekte/index?vehicle=3', '/vehicles/defects?vehicle=3'],
]);

it('drops the legacy defect handler', function () {
    $this->actingAs(vd_user('admin'));
    $this->get('/settings/vehicles/defects/handler')->assertNotFound();
    $this->post('/api/vehicles/defects-handler', ['action' => 'list'])->assertNotFound();
});
