<?php

use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Enotf\Models\EnotfCategory;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Logbook\Models\LogbookEntry;

uses(RefreshDatabase::class);

function ad_user(string ...$perms): User
{
    test()->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create(['personnel_id' => Personnel::factory()->create()->id]);
}

function ad_protocol(array $over = []): EnotfProtocol
{
    $p = (new EnotfProtocol)->forceFill($over + ['enr' => (string) random_int(100000, 999999)]);
    $p->save();

    return $p;
}

function ad_crew(User $user): Vehicle
{
    $vehicle = Vehicle::factory()->create(['service' => Vehicle::SERVICE_EMS]);
    test()->actingAs($user)->post('/enotf/login', ['pin' => '1234', 'vehicle_id' => $vehicle->id])->assertRedirect('/enotf/overview');

    return $vehicle;
}

it('limits the QM list to QM and super-admin, ignores an unknown sort and searches ENR and vehicle only', function () {
    ad_protocol(['enr' => '555001', 'vehicle_transport_label' => 'RTW Nord', 'patient_last_name' => 'Geheim']);
    ad_protocol(['enr' => '555002']);

    $this->get('/enotf/admin')->assertRedirect('/login');
    $this->actingAs(ad_user('enotf.view'))->get('/enotf/admin')->assertForbidden();
    $this->actingAs(User::factory()->superAdmin()->create())->get('/enotf/admin')->assertOk();

    $this->actingAs(ad_user('edivi.view'))->get('/enotf/admin?sort=patient_last_name;drop&dir=sideways&q=Nord')->assertOk()
        ->assertInertia(fn ($p) => $p->where('filters.sort', 'created_at')->where('filters.dir', 'desc')
            ->has('protocols.data', 1)->where('protocols.data.0.enr', '555001')->where('counts.open', 2));
    $this->get('/enotf/admin?q=Geheim')->assertInertia(fn ($p) => $p->has('protocols.data', 0));
});

it('hides only with the edit right and never deletes', function () {
    $p = ad_protocol();

    $this->actingAs(ad_user('edivi.view'))->post('/enotf/admin/hide', ['ids' => [$p->id]])->assertForbidden();
    expect($p->fresh()->hidden_at)->toBeNull();

    $editor = ad_user('edivi.edit');
    $this->actingAs($editor)->post('/enotf/admin/hide', ['ids' => [$p->id]])->assertRedirect();
    expect(EnotfProtocol::count())->toBe(1)->and($p->fresh()->hidden_at)->not->toBeNull()->and($p->fresh()->hidden_by)->toBe($editor->id);
});

it('hides empty protocols only after a preview and with its single-use token', function () {
    $empty = ad_protocol();
    $filled = ad_protocol(['patient_last_name' => 'X']);
    $editor = ad_user('edivi.edit');
    $filter = ['fields' => ['patient_last_name'], 'days' => 'all', 'state' => 'open'];

    $res = $this->actingAs($editor)->post('/enotf/admin/empty', $filter)->assertOk();
    $token = $res->viewData('page')['props']['preview']['token'];
    expect($res->viewData('page')['props']['preview']['count'])->toBe(1)->and($empty->fresh()->hidden_at)->toBeNull();

    $this->post('/enotf/admin/empty', $filter + ['token' => str_repeat('a', 40)])->assertSessionHasErrors('token');
    $this->post('/enotf/admin/empty', ['fields' => ['mission_date']] + $filter + ['token' => $token])->assertSessionHasErrors('token');
    expect($empty->fresh()->hidden_at)->toBeNull();

    $res = $this->post('/enotf/admin/empty', $filter);
    $token = $res->viewData('page')['props']['preview']['token'];
    $this->actingAs(ad_user('edivi.edit'))->post('/enotf/admin/empty', $filter + ['token' => $token])->assertSessionHasErrors('token');

    $res = $this->actingAs($editor)->post('/enotf/admin/empty', $filter);
    $token = $res->viewData('page')['props']['preview']['token'];
    $this->post('/enotf/admin/empty', $filter + ['token' => $token])->assertRedirect('/enotf/admin');
    expect($empty->fresh()->hidden_at)->not->toBeNull()->and($filled->fresh()->hidden_at)->toBeNull();
    $this->post('/enotf/admin/empty', $filter + ['token' => $token])->assertSessionHasErrors('token');
});

it('manages categories and quicklinks only with enotf.settings and refuses javascript: and data: URLs', function () {
    $this->actingAs(ad_user('edivi.edit'))->get('/settings/enotf/categories')->assertForbidden();
    $this->post('/settings/enotf/categories', ['name' => 'A', 'sort_order' => 0, 'active' => true])->assertForbidden();

    $admin = ad_user('enotf.settings');
    $this->actingAs($admin)->get('/settings/enotf/categories')->assertOk();
    $this->post('/settings/enotf/categories', ['name' => 'Leitlinien', 'sort_order' => 1, 'active' => true])->assertRedirect();
    $cat = EnotfCategory::sole();

    $link = ['enotf_category_id' => $cat->id, 'title' => 'T', 'icon' => 'book-open', 'sort_order' => 0, 'col_width' => 'col-6', 'active' => true];
    foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.example', 'http://plain.example', '/ok with space'] as $bad) {
        $this->post('/settings/enotf/quicklinks', $link + ['url' => $bad])->assertSessionHasErrors('url');
    }
    $this->post('/settings/enotf/quicklinks', array_merge($link, ['url' => '/enotf/overview', 'icon' => 'not-an-icon']))->assertSessionHasErrors('icon');
    $this->post('/settings/enotf/quicklinks', $link + ['url' => 'https://example.org/x'])->assertSessionHasNoErrors();
    $this->post('/settings/enotf/quicklinks', $link + ['url' => '/enotf/overview'])->assertSessionHasNoErrors();
    expect($cat->quicklinks()->count())->toBe(2);

    $this->delete("/settings/enotf/categories/{$cat->id}")->assertSessionHasErrors('category');
});

it('writes the logbook with the session vehicle and driver; a double submit makes one row', function () {
    $user = ad_user();
    $vehicle = ad_crew($user);
    $other = Vehicle::factory()->create();
    $key = $this->get('/enotf/fahrtenbuch')->assertOk()->viewData('page')['props']['formKey'];
    $body = ['key' => $key, 'trip_date' => '2026-10-20', 'departed_at' => '08:30', 'distance_km' => 4.5, 'trip_type' => 'einsatz',
        'vehicle_id' => $other->id, 'driver_name' => 'Fremder', 'source' => 'admin'];

    $this->post('/enotf/fahrtenbuch', $body)->assertRedirect('/enotf/fahrtenbuch');
    $this->post('/enotf/fahrtenbuch', $body)->assertRedirect('/enotf/fahrtenbuch');

    $e = LogbookEntry::sole();
    expect($e->vehicle_id)->toBe($vehicle->id)->and($e->source)->toBe('enotf')->and($e->driver_name)->toBe(trim($user->personnel->displayName()));
});

it('reports a defect for the session vehicle, never the one in the input', function () {
    $user = ad_user();
    $vehicle = ad_crew($user);
    $other = Vehicle::factory()->create();

    $this->get('/enotf/fahrzeuginfo')->assertOk();
    $this->post('/enotf/fahrzeuginfo/defects', ['title' => 'Blaulicht defekt', 'vehicle_id' => $other->id, 'is_operable' => false])->assertRedirect('/enotf/fahrzeuginfo');

    $d = VehicleDefect::sole();
    expect($d->vehicle_id)->toBe($vehicle->id)->and($d->reported_by)->toBe($user->id)->and($d->is_operable)->toBeFalse();
});

it('shows QM readers the open protocols on the dashboard: count, oldest and this week\'s released share', function () {
    config(['ignis.timezone' => 'Europe/Berlin']);
    Carbon::setTestNow('2026-10-21 10:00:00');
    ad_protocol(['created_at' => '2026-10-19 08:00:00']);
    ad_protocol(['created_at' => '2026-10-21 09:00:00']);
    ad_protocol(['created_at' => '2026-10-20 09:00:00', 'status' => 'released']);
    ad_protocol(['created_at' => '2026-10-18 21:00:00', 'status' => 'released']);
    ad_protocol(['created_at' => '2026-10-01 08:00:00', 'hidden_at' => now()]);
    ad_protocol(['created_at' => '2026-10-20 10:00:00', 'status' => 'withdrawn']);

    $this->actingAs(ad_user('edivi.view'))->get('/')->assertInertia(fn ($p) => $p
        ->where('layout.kpi', fn ($kpi) => in_array('enotf_open', $kpi->all(), true))
        ->where('widgets.enotf_open', ['open' => 2, 'oldest_at' => '2026-10-19T08:00:00+00:00', 'week' => 3, 'week_released' => 1, 'href' => '/enotf/admin?segment=open'])
        ->where('widgets.hints.hints.0.title', '2 eNOTF-Protokolle nicht freigegeben')
        ->where('widgets.hints.hints.0.since', '2026-10-19T08:00:00+00:00')
        ->where('widgets.hints.hints.0.progress.value', 33));

    $this->actingAs(ad_user('enotf.view'))->get('/')->assertInertia(fn ($p) => $p
        ->where('layout.kpi', fn ($kpi) => ! in_array('enotf_open', $kpi->all(), true))->missing('widgets.enotf_open'));
});
