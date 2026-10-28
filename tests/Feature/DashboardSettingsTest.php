<?php

use App\Models\DashboardCategory;
use App\Models\DashboardLink;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ds_user(string ...$perms): User
{
    $role = Role::create(['name' => 'S'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

const DS = '/settings/dashboard';

// ----- rights -----

it('lets guests and users without the right nowhere, 403 before 422', function () {
    $cat = DashboardCategory::factory()->create();
    $link = DashboardLink::factory()->for($cat, 'category')->create();
    $writes = [['post', DS.'/categories'], ['put', DS."/categories/{$cat->id}"], ['delete', DS."/categories/{$cat->id}"], ['post', DS.'/categories/reorder'],
        ['post', DS.'/links'], ['put', DS."/links/{$link->id}"], ['delete', DS."/links/{$link->id}"], ['post', DS.'/links/reorder']];

    $this->get(DS)->assertRedirect('/login');
    foreach ($writes as [$m, $url]) {
        $this->{$m}($url)->assertRedirect('/login');
    }
    foreach ([ds_user(), ds_user('vehicles.view', 'vehicles.manage')] as $nobody) {
        $this->actingAs($nobody)->get(DS)->assertForbidden();
        foreach ($writes as [$m, $url]) {
            $this->actingAs($nobody)->{$m}($url, [])->assertForbidden();
        }
    }
    expect(DashboardCategory::count())->toBe(1)->and(DashboardLink::count())->toBe(1);
});

it('admits dashboard.manage, admin and super admin', function () {
    foreach ([ds_user('dashboard.manage'), ds_user('admin'), User::factory()->superAdmin()->create()] as $user) {
        $this->actingAs($user)->get(DS)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Settings/Dashboard'));
        $this->actingAs($user)->post(DS.'/categories', ['title' => 'K'.$user->id])->assertRedirect();
    }
    expect(DashboardCategory::count())->toBe(3);
});

it('redirects the legacy index and keeps /dashboard a page', function () {
    $this->get('/settings/dashboard/index')->assertStatus(301)->assertRedirect(DS);
    $this->actingAs(ds_user())->get('/dashboard')->assertOk();
});

// ----- categories -----

it('creates, updates and deletes a category with the legacy audit texts', function () {
    $user = ds_user('dashboard.manage');

    $this->actingAs($user)->post(DS.'/categories', ['title' => '  Einsatz  ', 'sort_order' => '4'])->assertRedirect();
    $cat = DashboardCategory::sole();
    expect($cat->title)->toBe('Einsatz')->and($cat->sort_order)->toBe(4);

    $this->actingAs($user)->put(DS."/categories/{$cat->id}", ['title' => 'Verwaltung'])->assertRedirect();
    expect($cat->refresh()->title)->toBe('Verwaltung')->and($cat->sort_order)->toBe(4);

    DashboardLink::factory()->for($cat, 'category')->count(2)->create();
    $this->actingAs($user)->delete(DS."/categories/{$cat->id}")->assertRedirect();
    expect(DashboardCategory::count())->toBe(0)->and(DashboardLink::count())->toBe(0);

    $log = Activity::where('log_name', 'Dashboard')->orderBy('id')->get();
    expect($log->pluck('description')->all())->toBe(['Kategorie erstellt', "Kategorie aktualisiert [ID: {$cat->id}]", "Kategorie gelöscht [ID: {$cat->id}]"])
        ->and($log->pluck('details')->all())->toBe(['Titel: Einsatz', 'Titel: Verwaltung', 'Titel: Verwaltung'])
        ->and($log->every(fn ($a) => (bool) $a->is_global && $a->causer_id === $user->id))->toBeTrue();
});

it('puts a new category and a new link last when no position is sent', function () {
    $user = ds_user('dashboard.manage');
    $this->actingAs($user)->post(DS.'/categories', ['title' => 'A']);
    $this->actingAs($user)->post(DS.'/categories', ['title' => 'B']);
    $a = DashboardCategory::where('title', 'A')->first();
    $b = DashboardCategory::where('title', 'B')->first();
    expect($b->sort_order)->toBeGreaterThan($a->sort_order);

    $this->actingAs($user)->post(DS.'/links', ['dashboard_category_id' => $a->id, 'title' => 'x', 'url' => '/x']);
    $this->actingAs($user)->post(DS.'/links', ['dashboard_category_id' => $a->id, 'title' => 'y', 'url' => '/y']);
    expect(DashboardLink::where('title', 'y')->value('sort_order'))->toBeGreaterThan(DashboardLink::where('title', 'x')->value('sort_order'));
});

it('validates category input', function () {
    foreach ([
        'no title' => [[]],
        'blank title' => [['title' => '   ']],
        'long title' => [['title' => str_repeat('a', 256)]],
        'array title' => [['title' => ['x']]],
        'NUL only' => [['title' => "\0\0"]],
        'bad utf8' => [['title' => "\xC3\x28"]],
        'negative order' => [['title' => 'x', 'sort_order' => -1]],
        'huge order' => [['title' => 'x', 'sort_order' => 10000]],
        'array order' => [['title' => 'x', 'sort_order' => [1]]],
    ] as [$input]) {
        $this->actingAs(ds_user('dashboard.manage'))->post(DS.'/categories', $input)->assertSessionHasErrors();
        expect(DashboardCategory::count())->toBe(0);
    }
});

it('strips NUL bytes from titles', function () {
    $this->actingAs(ds_user('dashboard.manage'))->post(DS.'/categories', ['title' => "Ein\0satz"]);
    expect(DashboardCategory::sole()->title)->toBe('Einsatz');
});

// ----- links -----

it('creates, updates and deletes a link with the legacy audit texts', function () {
    $user = ds_user('dashboard.manage');
    $cat = DashboardCategory::factory()->create();

    $this->actingAs($user)->post(DS.'/links', ['dashboard_category_id' => $cat->id, 'title' => 'Fahrzeuge', 'url' => '/vehicles', 'icon' => 'fa-solid fa-truck', 'sort_order' => 3])->assertRedirect();
    $link = DashboardLink::sole();
    expect($link->only(['title', 'url', 'icon', 'sort_order', 'dashboard_category_id']))->toBe(['title' => 'Fahrzeuge', 'url' => '/vehicles', 'icon' => 'fa-solid fa-truck', 'sort_order' => 3, 'dashboard_category_id' => $cat->id]);

    $other = DashboardCategory::factory()->create();
    $this->actingAs($user)->put(DS."/links/{$link->id}", ['dashboard_category_id' => $other->id, 'title' => 'Mail', 'url' => 'mailto:a@example.org', 'icon' => ''])->assertRedirect();
    expect($link->refresh()->only(['title', 'url', 'icon', 'dashboard_category_id']))->toBe(['title' => 'Mail', 'url' => 'mailto:a@example.org', 'icon' => null, 'dashboard_category_id' => $other->id]);

    $this->actingAs($user)->delete(DS."/links/{$link->id}")->assertRedirect();
    expect(DashboardLink::count())->toBe(0);

    $log = Activity::where('log_name', 'Dashboard')->orderBy('id')->get();
    expect($log->pluck('description')->all())->toBe(['Verlinkung erstellt', "Verlinkung aktualisiert [ID: {$link->id}]", "Verlinkung gelöscht [ID: {$link->id}]"])
        ->and($log->pluck('details')->all())->toBe(['Titel: Fahrzeuge', 'Titel: Mail', 'Titel: Mail'])
        ->and($log->every(fn ($a) => (bool) $a->is_global))->toBeTrue();
});

it('requires an existing category for a link', function (array $extra) {
    $this->actingAs(ds_user('dashboard.manage'))->post(DS.'/links', ['title' => 'x', 'url' => '/x', ...$extra])->assertSessionHasErrors('dashboard_category_id');
    expect(DashboardLink::count())->toBe(0);
})->with(['missing' => [[]], 'unknown' => [['dashboard_category_id' => 999]], 'array' => [['dashboard_category_id' => [1]]], 'text' => [['dashboard_category_id' => 'abc']]]);

it('refuses hostile targets on create and update and keeps the stored one', function (string $url) {
    $user = ds_user('dashboard.manage');
    $cat = DashboardCategory::factory()->create();
    $this->actingAs($user)->post(DS.'/links', ['dashboard_category_id' => $cat->id, 'title' => 'x', 'url' => $url])->assertSessionHasErrors('url');
    $link = DashboardLink::factory()->for($cat, 'category')->create(['url' => '/ok']);
    $this->actingAs($user)->put(DS."/links/{$link->id}", ['dashboard_category_id' => $cat->id, 'title' => 'x', 'url' => $url])->assertSessionHasErrors('url');
    expect(DashboardLink::count())->toBe(1)->and($link->refresh()->url)->toBe('/ok');
})->with(['javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", 'data:text/html;base64,PHNjcmlwdD4=', 'vbscript:x', 'file:///etc/passwd', '//evil.example', '/\\evil.example', 'HTTPS://example.org', 'https://u:p@example.org', 'mailto:', 'example.org', '/'.str_repeat('b', 255)]);

it('accepts relative, https and mailto targets', function (string $url) {
    $cat = DashboardCategory::factory()->create();
    $this->actingAs(ds_user('dashboard.manage'))->post(DS.'/links', ['dashboard_category_id' => $cat->id, 'title' => 'x', 'url' => $url])->assertSessionDoesntHaveErrors();
    expect(DashboardLink::sole()->url)->toBe($url);
})->with(['/vehicles/defects?vehicle=3', 'https://example.org/a', 'http://example.org', 'mailto:info@example.org']);

it('validates the other link fields', function () {
    foreach ([
        'no title' => [['title' => ''], 'title'],
        'long title' => [['title' => str_repeat('a', 256)], 'title'],
        'no url' => [['url' => null], 'url'],
        'array url' => [['url' => ['/x']], 'url'],
        'upper icon' => [['icon' => 'Fa-Solid'], 'icon'],
        'icon chars' => [['icon' => 'fa-solid" onload="x'], 'icon'],
        'long icon' => [['icon' => str_repeat('a', 101)], 'icon'],
        'array icon' => [['icon' => ['fa']], 'icon'],
        'order high' => [['sort_order' => 10000], 'sort_order'],
        'order negative' => [['sort_order' => -5], 'sort_order'],
    ] as [$input, $error]) {
        $cat = DashboardCategory::factory()->create();
        $this->actingAs(ds_user('dashboard.manage'))->post(DS.'/links', ['dashboard_category_id' => $cat->id, 'title' => 'x', 'url' => '/x', ...$input])->assertSessionHasErrors($error);
        expect(DashboardLink::count())->toBe(0);
    }
});

// ----- reorder -----

it('reorders categories, skips unknown ids and audits', function () {
    $a = DashboardCategory::factory()->create(['sort_order' => 1]);
    $b = DashboardCategory::factory()->create(['sort_order' => 2]);
    $c = DashboardCategory::factory()->create(['sort_order' => 3]);

    $this->actingAs(ds_user('dashboard.manage'))->post(DS.'/categories/reorder', ['ids' => [$c->id, 9999, $a->id, $c->id]])->assertRedirect();

    expect([$c->refresh()->sort_order, $a->refresh()->sort_order, $b->refresh()->sort_order])->toBe([0, 1, 2]);
    expect(Activity::where('log_name', 'Dashboard')->where('is_global', true)->count())->toBe(1);
});

it('reorders links within and between categories and ignores foreign ids', function () {
    $x = DashboardCategory::factory()->create();
    $y = DashboardCategory::factory()->create();
    $l1 = DashboardLink::factory()->for($x, 'category')->create(['sort_order' => 1]);
    $l2 = DashboardLink::factory()->for($x, 'category')->create(['sort_order' => 2]);
    $l3 = DashboardLink::factory()->for($y, 'category')->create(['sort_order' => 1]);
    $user = ds_user('dashboard.manage');

    $this->actingAs($user)->post(DS.'/links/reorder', ['category_id' => $x->id, 'ids' => [$l2->id, 777, $l1->id]])->assertRedirect();
    expect([$l2->refresh()->sort_order, $l1->refresh()->sort_order])->toBe([0, 1]);

    $this->actingAs($user)->post(DS.'/links/reorder', ['category_id' => $y->id, 'ids' => [$l1->id, $l3->id]]);
    expect($l1->refresh()->only(['dashboard_category_id', 'sort_order']))->toBe(['dashboard_category_id' => $y->id, 'sort_order' => 0])
        ->and($l3->refresh()->sort_order)->toBe(1)
        ->and(DashboardLink::count())->toBe(3);
});

it('validates reorder input', function () {
    foreach ([
        'no ids' => ['/categories/reorder', []],
        'empty ids' => ['/categories/reorder', ['ids' => []]],
        'too many' => ['/categories/reorder', ['ids' => range(1, 501)]],
        'text id' => ['/categories/reorder', ['ids' => ['a']]],
        'nested id' => ['/categories/reorder', ['ids' => [[1]]]],
        'scalar ids' => ['/categories/reorder', ['ids' => '1,2']],
        'links without category' => ['/links/reorder', ['ids' => [1]]],
        'links unknown category' => ['/links/reorder', ['category_id' => 999, 'ids' => [1]]],
        'categories with category_id' => ['/categories/reorder', ['category_id' => 1, 'ids' => [1]]],
    ] as [$path, $input]) {
        $this->actingAs(ds_user('dashboard.manage'))->post(DS.$path, $input)->assertSessionHasErrors();
    }
});

it('accepts exactly 500 ids', function () {
    $this->actingAs(ds_user('dashboard.manage'))->post(DS.'/categories/reorder', ['ids' => range(1, 500)])->assertSessionDoesntHaveErrors();
});

// ----- page data -----

it('hands the settings page every category with its links and their positions', function () {
    $cat = DashboardCategory::factory()->create(['title' => 'K', 'sort_order' => 5]);
    DashboardLink::factory()->for($cat, 'category')->create(['title' => 'L', 'url' => 'https://example.org', 'icon' => 'fa-solid fa-house', 'sort_order' => 7]);

    $this->actingAs(ds_user('dashboard.manage'))->get(DS)->assertInertia(fn (Assert $p) => $p
        ->where('categories.0.title', 'K')->where('categories.0.sort_order', 5)
        ->where('categories.0.links.0.url', 'https://example.org')->where('categories.0.links.0.sort_order', 7));
});

it('throttles writes in the dashboard-write bucket', function () {
    $user = ds_user('dashboard.manage');
    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($user)->post(DS.'/categories', ['title' => 'x'])->assertRedirect();
    }
    $this->actingAs($user)->post(DS.'/categories', ['title' => 'x'])->assertStatus(429);
});
