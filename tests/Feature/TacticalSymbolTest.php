<?php

use App\Models\Role;
use App\Models\TacticalSymbolTemplate;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function tz_user(string ...$perms): User
{
    $role = Role::create(['name' => 'T'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function tz_vehicle(array $over = []): array
{
    return $over + ['name' => 'Florian 1/83-1', 'type' => 'RTW', 'identifier' => 'rtw_1', 'service' => 2, 'is_active' => true];
}

const TZ = '/settings/vehicles/symbol-templates';

// ----- rights -----

it('reads with view, writes with manage only, and leaves guests and others out', function () {
    $t = TacticalSymbolTemplate::factory()->create();
    $viewer = tz_user('vehicles.view');
    $manager = tz_user('vehicles.manage');
    $nobody = tz_user('audit.view');

    $writes = [['post', TZ], ['put', TZ."/{$t->id}"], ['delete', TZ."/{$t->id}"], ['post', TZ."/{$t->id}/apply"]];
    $this->get(TZ)->assertRedirect('/login');
    foreach ($writes as [$m, $url]) {
        $this->{$m}($url)->assertRedirect('/login');
    }
    foreach ($writes as [$m, $url]) {
        $this->actingAs($viewer)->{$m}($url)->assertForbidden();
        $this->actingAs($nobody)->{$m}($url)->assertForbidden();
    }
    $this->actingAs($nobody)->get(TZ)->assertForbidden();
    $this->actingAs($manager)->get(TZ)->assertForbidden();
    $this->actingAs($viewer)->get(TZ)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Vehicles/SymbolTemplates')->where('can.manage', false)->has('templates', 1)
        ->where('templates.0.name', $t->name)->where('templates.0.symbol.grundzeichen', 'kraftfahrzeug-landgebunden'));
    $this->actingAs(tz_user('vehicles.view', 'vehicles.manage'))->get(TZ)->assertInertia(fn (Assert $p) => $p->where('can.manage', true));
});

it('lists the distinct vehicle types sorted', function () {
    Vehicle::factory()->create(['type' => 'RTW']);
    Vehicle::factory()->create(['type' => 'RTW']);
    Vehicle::factory()->create(['type' => 'HLF']);

    $this->actingAs(tz_user('vehicles.view'))->get(TZ)->assertInertia(fn (Assert $p) => $p->where('types', ['HLF', 'RTW']));
});

// ----- template CRUD -----

it('creates, updates and deletes a template with the old audit texts', function () {
    $u = tz_user('vehicles.view', 'vehicles.manage');

    $this->actingAs($u)->post(TZ, ['name' => ' KdoW ', 'symbol' => ['grundzeichen' => 'fahrzeug', 'organisation' => 'feuerwehr', 'typ' => 'ELW 1', 'text' => '', 'bogus' => 'x']])->assertRedirect();
    $t = TacticalSymbolTemplate::firstOrFail();
    expect($t->name)->toBe('KdoW')->and($t->symbol)->toBe(['grundzeichen' => 'fahrzeug', 'organisation' => 'feuerwehr', 'typ' => 'ELW 1'])
        ->and($t->created_by)->toBe($u->id);

    $this->actingAs($u)->put(TZ."/{$t->id}", ['name' => 'KdoW neu', 'symbol' => ['grundzeichen' => 'ohne', 'symbol' => 'wasser']])->assertRedirect();
    expect($t->refresh()->name)->toBe('KdoW neu')->and($t->symbol)->toBe(['grundzeichen' => 'ohne', 'symbol' => 'wasser'])->and($t->created_by)->toBe($u->id);

    $this->actingAs($u)->delete(TZ."/{$t->id}")->assertRedirect();
    expect(TacticalSymbolTemplate::count())->toBe(0);

    $log = Activity::where('log_name', 'Fahrzeuge')->orderBy('id')->get();
    expect($log->pluck('description')->all())->toBe(['TZ-Vorlage gespeichert: KdoW', 'TZ-Vorlage gespeichert: KdoW neu', 'TZ-Vorlage gelöscht: KdoW neu'])
        ->and($log->every(fn ($a) => $a->is_global && $a->causer_id === $u->id))->toBeTrue();
});

it('refuses a name that exists in another case and allows keeping its own', function () {
    $u = tz_user('vehicles.manage');
    $a = TacticalSymbolTemplate::factory()->create(['name' => 'Löschfahrzeug']);
    $b = TacticalSymbolTemplate::factory()->create(['name' => 'Rettung']);
    $sym = ['grundzeichen' => 'fahrzeug'];

    $this->actingAs($u)->post(TZ, ['name' => 'LÖSCHFAHRZEUG', 'symbol' => $sym])->assertSessionHasErrors('name');
    $this->actingAs($u)->put(TZ."/{$b->id}", ['name' => 'löschfahrzeug', 'symbol' => $sym])->assertSessionHasErrors('name');
    expect(Activity::count())->toBe(0);
    $this->actingAs($u)->put(TZ."/{$a->id}", ['name' => 'LÖSCHFAHRZEUG', 'symbol' => $sym])->assertSessionHasNoErrors();
    expect(TacticalSymbolTemplate::count())->toBe(2)->and($a->refresh()->name)->toBe('LÖSCHFAHRZEUG');
});

it('validates name length, symbol content and shape', function () {
    foreach ([
        'no name' => [['symbol' => ['grundzeichen' => 'fahrzeug']], 'name'],
        'name 101' => [['name' => str_repeat('a', 101), 'symbol' => ['grundzeichen' => 'fahrzeug']], 'name'],
        'name array' => [['name' => ['a'], 'symbol' => ['grundzeichen' => 'fahrzeug']], 'name'],
        'only NUL' => [['name' => "\0", 'symbol' => ['grundzeichen' => 'fahrzeug']], 'name'],
        'no symbol' => [['name' => 'A'], 'symbol'],
        'symbol string' => [['name' => 'A', 'symbol' => 'fahrzeug'], 'symbol'],
        'no grundzeichen' => [['name' => 'A', 'symbol' => ['organisation' => 'thw']], 'symbol.grundzeichen'],
        'foreign value' => [['name' => 'A', 'symbol' => ['grundzeichen' => 'fahrzeug', 'organisation' => 'sith']], 'symbol.organisation'],
        'text 101' => [['name' => 'A', 'symbol' => ['grundzeichen' => 'fahrzeug', 'text' => str_repeat('x', 101)]], 'symbol.text'],
    ] as [$body, $field]) {
        $this->actingAs(tz_user('vehicles.manage'))->post(TZ, $body)->assertSessionHasErrors($field);
        foreach (session('errors')->getBag('default')->all() as $m) {
            expect($m)->not->toContain('validation.')->not->toContain('SQLSTATE');
        }
        expect(TacticalSymbolTemplate::count())->toBe(0)->and(Activity::count())->toBe(0);
    }
});

it('accepts a name of exactly 100 characters', function () {
    $this->actingAs(tz_user('vehicles.manage'))->post(TZ, ['name' => str_repeat('ä', 100), 'symbol' => ['grundzeichen' => 'fahrzeug']])->assertSessionHasNoErrors();
    expect(TacticalSymbolTemplate::count())->toBe(1);
});

it('answers 404 for a missing template and ignores non-numeric ids', function () {
    $u = tz_user('vehicles.manage');
    $this->actingAs($u)->put(TZ.'/999', ['name' => 'A', 'symbol' => ['grundzeichen' => 'fahrzeug']])->assertNotFound();
    $this->actingAs($u)->delete(TZ.'/999')->assertNotFound();
    $this->actingAs($u)->post(TZ.'/999/apply', ['type' => 'RTW'])->assertNotFound();
    $this->actingAs($u)->delete(TZ.'/abc')->assertNotFound();
});

// ----- apply to type -----

it('overwrites the symbol of vehicles of exactly that type only and keeps the symbol name', function () {
    $t = TacticalSymbolTemplate::factory()->create(['name' => 'Rettung', 'symbol' => ['grundzeichen' => 'fahrzeug', 'typ' => 'RTW']]);
    $a = Vehicle::factory()->create(['type' => 'RTW', 'tactical_symbol' => ['grundzeichen' => 'schienenfahrzeug', 'text' => 'alt'], 'tactical_symbol_name' => 'RTW 1']);
    $b = Vehicle::factory()->create(['type' => 'RTW', 'tactical_symbol' => null, 'tactical_symbol_name' => null]);
    $other = Vehicle::factory()->create(['type' => 'NEF', 'tactical_symbol' => ['grundzeichen' => 'person']]);
    $similar = Vehicle::factory()->create(['type' => 'RTW 2', 'tactical_symbol' => ['grundzeichen' => 'person']]);
    $u = tz_user('vehicles.manage');

    $this->actingAs($u)->post(TZ."/{$t->id}/apply", ['type' => ' RTW '])->assertRedirect()
        ->assertSessionHas('status', 'symbol_templates.applied')->assertSessionHas('status_params', ['count' => 2]);

    expect($a->refresh()->tactical_symbol)->toBe(['grundzeichen' => 'fahrzeug', 'typ' => 'RTW'])->and($a->tactical_symbol_name)->toBe('RTW 1')
        ->and($b->refresh()->tactical_symbol)->toBe(['grundzeichen' => 'fahrzeug', 'typ' => 'RTW'])
        ->and($other->refresh()->tactical_symbol)->toBe(['grundzeichen' => 'person'])
        ->and($similar->refresh()->tactical_symbol)->toBe(['grundzeichen' => 'person']);
    $log = Activity::where('log_name', 'Fahrzeuge')->sole();
    expect($log->description)->toBe("TZ-Vorlage 'Rettung' auf 2 Fahrzeuge vom Typ 'RTW' angewendet")->and((bool) $log->is_global)->toBeTrue();
});

it('applies to zero vehicles without error and validates the type', function () {
    $t = TacticalSymbolTemplate::factory()->create(['name' => 'T']);
    $u = tz_user('vehicles.manage');

    $this->actingAs($u)->post(TZ."/{$t->id}/apply", ['type' => 'GIBTS-NICHT'])->assertRedirect()->assertSessionHas('status_params', ['count' => 0]);
    expect(Activity::first()->description)->toBe("TZ-Vorlage 'T' auf 0 Fahrzeuge vom Typ 'GIBTS-NICHT' angewendet");

    foreach ([[], ['type' => ''], ['type' => ['RTW']], ['type' => str_repeat('a', 256)], ['type' => "\0"]] as $body) {
        $this->actingAs($u)->post(TZ."/{$t->id}/apply", $body)->assertSessionHasErrors('type');
    }
    expect(Activity::count())->toBe(1);
});

// ----- vehicle form -----

it('stores the symbol and its name from the vehicle form and nulls an empty one', function () {
    $u = tz_user('vehicles.view', 'vehicles.manage');

    $this->actingAs($u)->post('/vehicles', tz_vehicle([
        'tactical_symbol' => ['grundzeichen' => 'kraftfahrzeug-gelaendegaengig', 'organisation' => 'feuerwehr', 'fachaufgabe' => 'brandbekaempfung', 'einheit' => 'gruppe', 'symbol' => '', 'typ' => 'HLF20', 'text' => 'LF 20', 'x' => 'y'],
        'tactical_symbol_name' => '  Florian 1 ',
    ]))->assertRedirect();
    $v = Vehicle::sole();
    expect($v->tactical_symbol)->toBe(['grundzeichen' => 'kraftfahrzeug-gelaendegaengig', 'organisation' => 'feuerwehr', 'fachaufgabe' => 'brandbekaempfung', 'einheit' => 'gruppe', 'typ' => 'HLF20', 'text' => 'LF 20'])
        ->and($v->tactical_symbol_name)->toBe('Florian 1');

    $this->actingAs($u)->put("/vehicles/{$v->id}", tz_vehicle(['tactical_symbol' => ['grundzeichen' => '', 'typ' => ''], 'tactical_symbol_name' => '']))->assertRedirect();
    expect($v->refresh()->tactical_symbol)->toBeNull()->and($v->tactical_symbol_name)->toBeNull();
});

it('keeps the stored symbol when the form does not send one', function () {
    $v = Vehicle::factory()->create(['tactical_symbol' => ['grundzeichen' => 'person'], 'tactical_symbol_name' => 'N']);

    $this->actingAs(tz_user('vehicles.manage'))->put("/vehicles/{$v->id}", tz_vehicle())->assertRedirect();
    expect($v->refresh()->tactical_symbol)->toBe(['grundzeichen' => 'person'])->and($v->tactical_symbol_name)->toBe('N');
});

it('rejects a foreign symbol value, an array name and a long name in the vehicle form', function (array $over, string $field) {
    $this->actingAs(tz_user('vehicles.manage'))->post('/vehicles', tz_vehicle($over))->assertSessionHasErrors($field);
    expect(Vehicle::count())->toBe(0);
})->with([
    'foreign grundzeichen' => [['tactical_symbol' => ['grundzeichen' => 'ufo']], 'tactical_symbol.grundzeichen'],
    'text 101' => [['tactical_symbol' => ['text' => str_repeat('a', 101)]], 'tactical_symbol.text'],
    'symbol as string' => [['tactical_symbol' => 'fahrzeug'], 'tactical_symbol'],
    'name 101' => [['tactical_symbol_name' => str_repeat('a', 101)], 'tactical_symbol_name'],
    'name array' => [['tactical_symbol_name' => ['a']], 'tactical_symbol_name'],
]);

it('passes the symbol to the form and the detail page, and the templates to the form', function () {
    TacticalSymbolTemplate::factory()->create(['name' => 'Vorlage']);
    $v = Vehicle::factory()->create(['tactical_symbol' => ['grundzeichen' => 'person'], 'tactical_symbol_name' => 'N']);

    $this->actingAs(tz_user('vehicles.manage'))->get("/vehicles/{$v->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->where('vehicle.tactical_symbol.grundzeichen', 'person')->where('vehicle.tactical_symbol_name', 'N')
        ->where('templates.0.name', 'Vorlage'));
    $this->actingAs(tz_user('vehicles.manage'))->get('/vehicles/create')->assertInertia(fn (Assert $p) => $p->where('templates.0.name', 'Vorlage'));
    $this->actingAs(tz_user('vehicles.view'))->get("/vehicles/{$v->id}")->assertInertia(fn (Assert $p) => $p
        ->where('vehicle.tactical_symbol.grundzeichen', 'person')->where('vehicle.tactical_symbol_name', 'N'));
});

// ----- legacy -----

it('drops the old template API and import handlers', function () {
    $u = tz_user('vehicles.manage', 'vehicles.view');

    $this->actingAs($u)->get('/api/vehicles/tz-templates?action=list')->assertNotFound();
    $this->actingAs($u)->post('/api/vehicles/tz-templates', ['action' => 'save', 'name' => 'x'])->assertNotFound();
    $this->actingAs($u)->post('/settings/vehicles/import-handler', ['action' => 'import'])->assertNotFound();
});

// ----- review fixes -----

it('gives admin and super admin the same rights as view plus manage', function () {
    $t = TacticalSymbolTemplate::factory()->create();

    foreach ([tz_user('admin'), User::factory()->superAdmin()->create()] as $u) {
        $this->actingAs($u)->get(TZ)->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.manage', true));
        $this->actingAs($u)->post(TZ.'/'.$t->id.'/apply', ['type' => 'RTW'])->assertRedirect();
        $this->actingAs($u)->put(TZ.'/'.$t->id, ['name' => 'N'.$u->id, 'symbol' => ['grundzeichen' => 'fahrzeug']])->assertSessionHasNoErrors();
    }
    $this->actingAs(tz_user('admin'))->delete(TZ.'/'.$t->id)->assertRedirect();
    expect(TacticalSymbolTemplate::count())->toBe(0);
});

it('maps a database unique violation to the name error', function () {
    $dup = fn (TacticalSymbolTemplate $m) => DB::table('tactical_symbol_templates')->insert(['name' => $m->name, 'symbol' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $u = tz_user('vehicles.manage');
    $sym = ['grundzeichen' => 'fahrzeug'];

    TacticalSymbolTemplate::creating($dup);
    $this->actingAs($u)->post(TZ, ['name' => 'Rennen', 'symbol' => $sym])->assertSessionHasErrors(['name' => trans('symbols.error.name_taken')]);
    TacticalSymbolTemplate::flushEventListeners();
    expect(Activity::count())->toBe(0);

    $t = TacticalSymbolTemplate::factory()->create(['name' => 'Alt']);
    TacticalSymbolTemplate::updating($dup);
    $this->actingAs($u)->put(TZ.'/'.$t->id, ['name' => 'Neu', 'symbol' => $sym])->assertSessionHasErrors(['name' => trans('symbols.error.name_taken')]);
    TacticalSymbolTemplate::flushEventListeners();
    expect($t->refresh()->name)->toBe('Alt')->and(Activity::count())->toBe(0);
});

it('rejects a list-shaped symbol and strips the CDATA end marker', function () {
    $u = tz_user('vehicles.manage');

    $this->actingAs($u)->post('/vehicles', tz_vehicle(['tactical_symbol' => ['fahrzeug']]))->assertSessionHasErrors('tactical_symbol');
    $this->actingAs($u)->post(TZ, ['name' => 'L', 'symbol' => ['fahrzeug']])->assertSessionHasErrors('symbol');
    expect(Vehicle::count())->toBe(0)->and(TacticalSymbolTemplate::count())->toBe(0);

    $this->actingAs($u)->post('/vehicles', tz_vehicle(['tactical_symbol' => ['grundzeichen' => 'fahrzeug', 'text' => 'a]]>b', 'typ' => ']]>'], 'tactical_symbol_name' => 'x]]>y']))->assertSessionHasNoErrors();
    $v = Vehicle::sole();
    expect($v->tactical_symbol)->toBe(['grundzeichen' => 'fahrzeug', 'text' => 'ab'])->and($v->tactical_symbol_name)->toBe('xy');
});
