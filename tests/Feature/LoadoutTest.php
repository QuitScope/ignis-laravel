<?php

use App\Models\LoadoutCategory;
use App\Models\LoadoutItem;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ld_user(string ...$perms): User
{
    $role = Role::create(['name' => 'L'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

const LD = '/settings/vehicles/loadouts';

// ----- rights -----

it('reads with view, writes with manage only, and leaves guests and others out', function () {
    $cat = LoadoutCategory::factory()->create();
    $item = LoadoutItem::factory()->for($cat, 'category')->create();
    $viewer = ld_user('vehicles.view');
    $manager = ld_user('vehicles.manage');
    $nobody = ld_user('audit.view');

    $writes = [['post', LD.'/categories'], ['put', LD."/categories/{$cat->id}"], ['delete', LD."/categories/{$cat->id}"],
        ['post', LD."/categories/{$cat->id}/items"], ['put', LD."/items/{$item->id}"], ['patch', LD."/items/{$item->id}"],
        ['delete', LD."/items/{$item->id}"], ['post', LD.'/items/reorder']];
    $this->get(LD)->assertRedirect('/login');
    foreach ($writes as [$m, $url]) {
        $this->{$m}($url)->assertRedirect('/login');
    }
    foreach ($writes as [$m, $url]) {
        $this->actingAs($viewer)->{$m}($url)->assertForbidden();
        $this->actingAs($nobody)->{$m}($url)->assertForbidden();
    }
    $this->actingAs($nobody)->get(LD)->assertForbidden();
    $this->actingAs($manager)->get(LD)->assertForbidden();
    $this->actingAs($viewer)->get(LD)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Vehicles/Loadouts')->where('can.manage', false));
    $this->actingAs(ld_user('vehicles.view', 'vehicles.manage'))->get(LD)->assertInertia(fn (Assert $p) => $p->where('can.manage', true));
});

// ----- page data -----

it('lists categories with items in order and the known vehicle types', function () {
    Vehicle::factory()->create(['type' => 'NEF']);
    LoadoutCategory::factory()->create(['title' => 'B', 'sort_order' => 2, 'vehicle_type' => 'RTW']);
    $a = LoadoutCategory::factory()->create(['title' => 'A', 'sort_order' => 1, 'vehicle_type' => null]);
    LoadoutItem::factory()->for($a, 'category')->create(['title' => 'second', 'sort_order' => 2]);
    LoadoutItem::factory()->for($a, 'category')->create(['title' => 'first', 'sort_order' => 1]);

    $this->actingAs(ld_user('vehicles.view'))->get(LD)->assertInertia(fn (Assert $p) => $p
        ->where('categories.0.title', 'A')->where('categories.1.title', 'B')
        ->where('categories.0.items.0.title', 'first')->where('categories.0.items.1.title', 'second')
        ->where('types', ['NEF', 'RTW']));
});

// ----- categories -----

it('creates, updates and deletes a category with audit', function () {
    $m = ld_user('vehicles.manage');
    $this->actingAs($m)->post(LD.'/categories', ['title' => '  Rucksack ', 'compartment' => 0, 'vehicle_type' => ' ', 'sort_order' => 3])->assertRedirect();
    $cat = LoadoutCategory::firstOrFail();
    expect($cat->title)->toBe('Rucksack')->and($cat->vehicle_type)->toBeNull()->and($cat->sort_order)->toBe(3);

    $this->actingAs($m)->put(LD."/categories/{$cat->id}", ['title' => 'Fach', 'compartment' => 2, 'vehicle_type' => 'RTW', 'sort_order' => 1])->assertRedirect();
    expect($cat->refresh()->only('title', 'compartment', 'vehicle_type'))->toBe(['title' => 'Fach', 'compartment' => 2, 'vehicle_type' => 'RTW']);

    LoadoutItem::factory()->for($cat, 'category')->count(2)->create();
    $this->actingAs($m)->delete(LD."/categories/{$cat->id}")->assertRedirect();
    expect(LoadoutCategory::count())->toBe(0)->and(LoadoutItem::count())->toBe(0);

    $log = Activity::where('log_name', 'Fahrzeuge')->orderBy('id')->get();
    expect($log->pluck('description')->all())->toBe([
        "Beladeliste Kategorie erstellt [ID: {$cat->id}]", "Beladeliste Kategorie aktualisiert [ID: {$cat->id}]", "Beladeliste Kategorie gelöscht [ID: {$cat->id}]",
    ])->and($log[0]->getAttribute('details'))->toBe('Titel: Rucksack')
        ->and((bool) $log[0]->getAttribute('is_global'))->toBeTrue()
        ->and($log[0]->properties['context'] ?? [])->not->toHaveKey('id');
});

it('validates categories explicitly', function () {
    $m = ld_user('vehicles.manage');
    foreach ([
        ['title' => '', 'compartment' => 0],
        ['title' => str_repeat('x', 256), 'compartment' => 0],
        ['title' => ['x'], 'compartment' => 0],
        ['title' => 'T', 'compartment' => 3],
        ['title' => 'T', 'compartment' => 'a'],
        ['title' => 'T', 'compartment' => 0, 'vehicle_type' => str_repeat('x', 256)],
        ['title' => 'T', 'compartment' => 0, 'vehicle_type' => ['x']],
        ['title' => 'T', 'compartment' => 0, 'sort_order' => 'x'],
    ] as $bad) {
        $this->actingAs($m)->postJson(LD.'/categories', $bad)->assertStatus(422);
    }
    expect(LoadoutCategory::count())->toBe(0);
    $res = $this->actingAs($m)->postJson(LD.'/categories', ['title' => '', 'compartment' => 9])->json('errors');
    expect($res['title'][0])->not->toStartWith('validation.')->and($res['compartment'][0])->not->toStartWith('validation.');
});

it('ignores mass-assigned extras on a category', function () {
    $m = ld_user('vehicles.manage');
    $this->actingAs($m)->post(LD.'/categories', ['title' => 'T', 'compartment' => 1, 'id' => 99, 'created_at' => '2000-01-01'])->assertRedirect();
    expect(LoadoutCategory::firstOrFail()->id)->not->toBe(99);
});

// ----- items -----

it('creates, updates, moves and deletes an item with audit', function () {
    $m = ld_user('vehicles.manage');
    $c1 = LoadoutCategory::factory()->create();
    $c2 = LoadoutCategory::factory()->create();

    $this->actingAs($m)->post(LD."/categories/{$c1->id}/items", ['title' => ' Tupfer ', 'quantity' => 4])->assertRedirect();
    $this->actingAs($m)->post(LD."/categories/{$c1->id}/items", ['title' => 'Schere', 'quantity' => 0])->assertRedirect();
    [$first, $second] = LoadoutItem::orderBy('id')->get()->all();
    expect($first->title)->toBe('Tupfer')->and($second->sort_order)->toBeGreaterThan($first->sort_order);

    $this->actingAs($m)->put(LD."/items/{$first->id}", ['title' => 'Kompresse', 'quantity' => 7, 'category_id' => $c2->id])->assertRedirect();
    expect($first->refresh()->only('title', 'quantity', 'loadout_category_id'))->toBe(['title' => 'Kompresse', 'quantity' => 7, 'loadout_category_id' => $c2->id]);

    $this->actingAs($m)->delete(LD."/items/{$first->id}")->assertRedirect();
    expect(LoadoutItem::count())->toBe(1);

    expect(Activity::where('log_name', 'Fahrzeuge')->orderBy('id')->pluck('description')->all())->toBe([
        "Beladeliste Gegenstand erstellt [ID: {$first->id}]", "Beladeliste Gegenstand erstellt [ID: {$second->id}]",
        "Beladeliste Gegenstand aktualisiert [ID: {$first->id}]", "Beladeliste Gegenstand gelöscht [ID: {$first->id}]",
    ]);
});

it('updates only the quantity inline', function () {
    $item = LoadoutItem::factory()->create(['title' => 'Keep', 'quantity' => 1]);
    $m = ld_user('vehicles.manage');
    $this->actingAs($m)->patch(LD."/items/{$item->id}", ['quantity' => 9, 'title' => 'Hack'])->assertRedirect();
    expect($item->refresh()->only('title', 'quantity'))->toBe(['title' => 'Keep', 'quantity' => 9]);
    $this->actingAs($m)->patchJson(LD."/items/{$item->id}", [])->assertStatus(422);
});

it('refuses bad item input: negative, too large, non-integer, empty title, foreign category', function () {
    $cat = LoadoutCategory::factory()->create();
    $item = LoadoutItem::factory()->for($cat, 'category')->create(['quantity' => 2]);
    $m = ld_user('vehicles.manage');
    foreach ([['title' => 'T', 'quantity' => -1], ['title' => 'T', 'quantity' => 100001], ['title' => 'T', 'quantity' => 1.5],
        ['title' => 'T', 'quantity' => 'x'], ['title' => 'T', 'quantity' => [1]], ['title' => '', 'quantity' => 1],
        ['title' => str_repeat('x', 256), 'quantity' => 1]] as $bad) {
        $this->actingAs($m)->postJson(LD."/categories/{$cat->id}/items", $bad)->assertStatus(422);
        $this->actingAs($m)->putJson(LD."/items/{$item->id}", $bad)->assertStatus(422);
    }
    $this->actingAs($m)->patchJson(LD."/items/{$item->id}", ['quantity' => -1])->assertStatus(422);
    $this->actingAs($m)->putJson(LD."/items/{$item->id}", ['title' => 'T', 'quantity' => 1, 'category_id' => 9999])->assertStatus(422);
    expect(LoadoutItem::count())->toBe(1)->and($item->refresh()->quantity)->toBe(2);
    $this->actingAs($m)->post(LD.'/categories/9999/items', ['title' => 'T', 'quantity' => 1])->assertNotFound();
});

// ----- reorder -----

it('reorders across categories, ignores unknown ids, and audits', function () {
    $c1 = LoadoutCategory::factory()->create();
    $c2 = LoadoutCategory::factory()->create();
    [$a, $b] = LoadoutItem::factory()->for($c1, 'category')->count(2)->sequence(['sort_order' => 0], ['sort_order' => 1])->create()->all();
    $x = LoadoutItem::factory()->for($c2, 'category')->create(['sort_order' => 0]);

    $this->actingAs(ld_user('vehicles.manage'))
        ->post(LD.'/items/reorder', ['category_id' => $c2->id, 'ids' => [$b->id, 424242, $x->id, $a->id, $b->id]])->assertRedirect();

    expect(LoadoutItem::where('loadout_category_id', $c2->id)->orderBy('sort_order')->pluck('id')->all())->toBe([$b->id, $x->id, $a->id])
        ->and(LoadoutItem::where('loadout_category_id', $c2->id)->orderBy('sort_order')->pluck('sort_order')->all())->toBe([0, 1, 2])
        ->and(LoadoutItem::count())->toBe(3);
    expect(Activity::where('description', 'Beladeliste Reihenfolge geändert')->where('log_name', 'Fahrzeuge')->count())->toBe(1);
});

it('validates reorder input and changes nothing on refusal', function () {
    $c = LoadoutCategory::factory()->create();
    $i = LoadoutItem::factory()->for($c, 'category')->create(['sort_order' => 5]);
    $m = ld_user('vehicles.manage');
    foreach ([[], ['category_id' => $c->id], ['category_id' => $c->id, 'ids' => []], ['category_id' => $c->id, 'ids' => 'x'],
        ['category_id' => $c->id, 'ids' => range(1, 501)], ['category_id' => $c->id, 'ids' => [['a']]], ['category_id' => 9999, 'ids' => [$i->id]],
        ['category_id' => $c->id, 'ids' => [0]]] as $bad) {
        $this->actingAs($m)->postJson(LD.'/items/reorder', $bad)->assertStatus(422);
    }
    expect($i->refresh()->sort_order)->toBe(5)->and(Activity::count())->toBe(0);
});

// ----- vehicle tab -----

it('matches loadout categories to a vehicle by exact type, null meaning all', function () {
    $rtw = Vehicle::factory()->create(['type' => 'RTW']);
    $all = LoadoutCategory::factory()->create(['title' => 'All', 'vehicle_type' => null, 'sort_order' => 2]);
    $mine = LoadoutCategory::factory()->create(['title' => 'Mine', 'vehicle_type' => 'RTW', 'sort_order' => 1]);
    LoadoutCategory::factory()->create(['title' => 'Other', 'vehicle_type' => 'NEF']);
    LoadoutCategory::factory()->create(['title' => 'Prefix', 'vehicle_type' => 'RTW2']);
    LoadoutItem::factory()->for($mine, 'category')->create(['title' => 'Tupfer', 'quantity' => 3]);

    expect($rtw->loadoutCategories()->pluck('title')->all())->toBe(['Mine', 'All']);

    $this->actingAs(ld_user('vehicles.view'))->get("/vehicles/{$rtw->id}")->assertInertia(fn (Assert $p) => $p
        ->where('loadout.0.title', 'Mine')->where('loadout.0.items.0.title', 'Tupfer')->where('loadout.0.items.0.quantity', 3)
        ->where('loadout.1.id', $all->id)->has('loadout', 2)->where('can.manageLoadout', false));
});

// ----- legacy URLs -----

it('redirects the old page and drops the old handler', function () {
    $this->get('/settings/vehicles/vehload/index')->assertRedirect(LD)->assertStatus(301);
    $this->get('/settings/fahrzeuge/beladelisten/index')->assertRedirect(LD)->assertStatus(301);
    $this->actingAs(ld_user('vehicles.manage'))->post('/settings/vehicles/vehload/beladung_handler', ['action' => 'add_category'])->assertNotFound();
});

it('accepts exactly 500 ids in a reorder', function () {
    $c = LoadoutCategory::factory()->create();
    $this->actingAs(ld_user('vehicles.manage'))->post(LD.'/items/reorder', ['category_id' => $c->id, 'ids' => range(1, 500)])->assertRedirect();
});

it('appends a moved item at the end of the target category', function () {
    $c1 = LoadoutCategory::factory()->create();
    $c2 = LoadoutCategory::factory()->create();
    $item = LoadoutItem::factory()->for($c1, 'category')->create();
    LoadoutItem::factory()->for($c2, 'category')->create(['sort_order' => 7]);
    $this->actingAs(ld_user('vehicles.manage'))->put(LD."/items/{$item->id}", ['title' => 'T', 'quantity' => 1, 'category_id' => $c2->id])->assertRedirect();
    expect($item->refresh()->sort_order)->toBe(8);
});

it('keeps the priority of a category edited without one', function () {
    $c = LoadoutCategory::factory()->create(['sort_order' => 5]);
    $this->actingAs(ld_user('vehicles.manage'))->put(LD."/categories/{$c->id}", ['title' => 'New', 'compartment' => 0])->assertRedirect();
    expect($c->refresh()->only('title', 'sort_order'))->toBe(['title' => 'New', 'sort_order' => 5]);
});

it('strips NUL bytes and refuses invalid UTF-8 in titles', function () {
    $m = ld_user('vehicles.manage');
    $cat = LoadoutCategory::factory()->create();
    $this->actingAs($m)->post(LD.'/categories', ['title' => "Fa\0ch", 'compartment' => 0])->assertRedirect();
    $this->actingAs($m)->post(LD."/categories/{$cat->id}/items", ['title' => "Tup\0fer", 'quantity' => 1])->assertRedirect();
    expect(LoadoutCategory::orderByDesc('id')->first()->title)->toBe('Fach')->and(LoadoutItem::firstOrFail()->title)->toBe('Tupfer');
    $this->actingAs($m)->postJson(LD.'/categories', ['title' => "bad\xB1", 'compartment' => 0])->assertStatus(422);
    $this->actingAs($m)->postJson(LD."/categories/{$cat->id}/items", ['title' => "bad\xB1", 'quantity' => 1])->assertStatus(422);
});
