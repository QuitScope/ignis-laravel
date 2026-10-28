<?php

use App\Dashboard\DashboardWidgets;
use App\Dashboard\Widget;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Document;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\Poi;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ht_user(string ...$perms): User
{
    $role = Role::create(['name' => 'H'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function ht_vehicle_queries(): int
{
    return count(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/\bvehicles?\b|vehicle_defects/', $q['query']) === 1));
}

it('renders for a fresh install without data and without widgets for a plain account', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Home')->where('app.version', fn ($v) => is_string($v) && $v !== '')
        ->where('layout.top', [])->where('layout.kpi', [])->where('layout.main', ['own_documents', 'own_applications', 'own_protocols', 'own_incidents'])->where('layout.side', [])
        ->where('widgets.own_documents', ['items' => [], 'total' => 0, 'reason' => 'no_profile', 'href' => '/me/documents'])
        ->where('header.actions', [['label' => 'home.apply', 'href' => '/forms']]));
});

it('carries the instant of the header; the browser formats it', function () {
    Carbon::setTestNow('2026-10-01 22:30:00');
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('header.date', Carbon::parse('2026-10-01 22:30:00')->toIso8601String()));
});

it('lists the widgets per area in order, by right', function () {
    $this->actingAs(ht_user('admin'))->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('layout.top', ['checklist'])
        ->where('layout.kpi', ['incidents_kpi', 'vehicle_kpi', 'enotf_open'])
        ->where('layout.main', ['incidents', 'vehicles', 'own_documents', 'own_applications', 'own_protocols', 'own_incidents'])
        ->where('layout.side', ['hints']));

    $this->actingAs(ht_user('vehicles.view'))->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('layout.top', [])->where('layout.kpi', ['vehicle_kpi'])->where('layout.main', ['vehicles', 'own_documents', 'own_applications', 'own_protocols', 'own_incidents'])->where('layout.side', ['hints']));

    $this->actingAs(ht_user('vehicles.manage'))->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('layout.kpi', [])->where('layout.main', ['own_documents', 'own_applications', 'own_protocols', 'own_incidents'])->where('layout.side', []));
});

it('never queries vehicles for an account without vehicle.view', function () {
    Vehicle::factory()->count(3)->create(['fms_status' => '6']);
    $user = ht_user('dashboard.manage');

    DB::enableQueryLog();
    $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('layout.kpi', [])->missing('widgets.vehicles')->missing('widgets.vehicle_kpi')->missing('widgets.hints'));

    expect(ht_vehicle_queries())->toBe(0);
});

it('does not evaluate the widgets on a partial reload that does not ask for them', function () {
    Vehicle::factory()->create(['fms_status' => '1', 'fms_status_at' => now()]);
    $user = ht_user('vehicles.view');

    DB::enableQueryLog();
    $this->actingAs($user)->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Inertia-Partial-Component' => 'Home', 'X-Inertia-Partial-Data' => 'header'])
        ->assertOk()->assertJsonPath('props.header.date', fn ($d) => is_string($d))->assertJsonMissingPath('props.widgets');

    expect(ht_vehicle_queries())->toBe(0);
});

function ht_partial(string $only): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Inertia-Partial-Component' => 'Home', 'X-Inertia-Partial-Data' => $only];
}

it('refreshes all allowed widgets on only: widgets', function () {
    Vehicle::factory()->create(['fms_status' => '1', 'fms_status_at' => now()]);
    $user = ht_user('vehicles.view');

    $this->actingAs($user)->get('/', ht_partial('widgets'))
        ->assertOk()->assertJsonPath('props.widgets.vehicle_kpi.ready', 1)->assertJsonPath('props.widgets.vehicles.ready', 1)
        ->assertJsonPath('props.widgets.hints.hints.0.tone', 'ok')->assertJsonPath('props.widgets.own_documents.reason', 'no_profile')->assertJsonMissingPath('props.layout');
});

it('answers a single widget request without the right with null and without vehicle queries', function () {
    Vehicle::factory()->create(['fms_status' => '6']);
    $user = ht_user('dashboard.manage');

    DB::enableQueryLog();
    $res = $this->actingAs($user)->get('/', ht_partial('widgets.vehicles'))->assertOk();

    expect(data_get($res->json(), 'props.widgets.vehicles'))->toBeNull()->and(ht_vehicle_queries())->toBe(0);
});

it('keeps the page alive when a widget throws', function () {
    $this->app->make(DashboardWidgets::class)->register(new class implements Widget
    {
        public function key(): string
        {
            return 'boom';
        }

        public function area(): string
        {
            return 'side';
        }

        public function order(): int
        {
            return 1;
        }

        public function allowed(User $user): bool
        {
            return true;
        }

        public function data(User $user): ?array
        {
            throw new RuntimeException('broken');
        }
    });

    $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('widgets.boom', null));
});

it('shows the checklist to admins only and drops it when the setup is done', function () {
    $this->actingAs(ht_user('admin'))->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('widgets.checklist.steps.0.key', 'ranks')->where('widgets.checklist.steps.0.next', true));
    $this->actingAs(ht_user('vehicles.view'))->get('/')->assertInertia(fn (Assert $p) => $p->missing('widgets.checklist'));
});

it('builds the hints from status 6 and blocking defects, calm otherwise', function () {
    $user = ht_user('vehicles.view');
    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('widgets.hints.hints', [['tone' => 'ok', 'icon' => 'circle-check', 'title' => 'Nichts wartet auf dich', 'text' => 'Offene Protokolle, Fahrzeuge außer Dienst und deine Aufgaben erscheinen hier.']]));

    $a = Vehicle::factory()->create(['name' => 'RTW 1', 'fms_status' => '6', 'fms_status_at' => '2026-10-01 10:00:00']);
    Vehicle::factory()->create(['name' => 'Gone', 'fms_status' => '6', 'is_active' => false]);
    $b = Vehicle::factory()->create(['name' => 'KTW 2', 'fms_status' => '1', 'fms_status_at' => now()]);
    VehicleDefect::factory()->blocking()->create(['vehicle_id' => $b->id]);
    VehicleDefect::factory()->create(['vehicle_id' => $a->id]);

    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('widgets.hints.hints', [
            ['tone' => 'warn', 'icon' => 'clock', 'href' => "/vehicles/{$a->id}", 'text' => 'Nicht einsatzbereit.', 'title' => 'RTW 1 seit :since Status 6', 'since' => '2026-10-01T10:00:00+00:00'],
            ['tone' => 'danger', 'icon' => 'triangle-exclamation', 'title' => 'Fahrzeug mit blockierendem Mangel: KTW 2', 'href' => '/vehicles/defects'],
        ]));

    Vehicle::factory()->create(['name' => 'HLF 3', 'fms_status' => '6']);
    $this->actingAs($user)->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('widgets.hints.hints.0', ['tone' => 'warn', 'icon' => 'clock', 'href' => '/vehicles', 'title' => '2 Fahrzeuge in Status 6', 'text' => 'Nicht einsatzbereit: HLF 3, RTW 1.']));
});

it('shows the own documents of a linked account: issued editor documents and legacy rows, never drafts or foreign ones', function () {
    $person = Personnel::factory()->create();
    $me = User::factory()->create(['personnel_id' => $person->id]);
    LegacyDocument::factory()->create(['personnel_id' => $person->id, 'public_id' => 'ABCD-EFGH-JKLM', 'type' => 1, 'issued_on' => '2024-05-01']);
    Document::factory()->issued()->create(['personnel_id' => $person->id, 'public_id' => '0000001', 'title' => 'Urkunde', 'issued_at' => '2025-01-02 10:00:00']);
    Document::factory()->create(['personnel_id' => $person->id, 'public_id' => '0000002', 'title' => 'Entwurf']);
    Document::factory()->issued()->create(['personnel_id' => Personnel::factory()->create()->id, 'title' => 'Fremd']);

    $this->actingAs($me)->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('widgets.own_documents.total', 2)
        ->where('widgets.own_documents.href', '/me/documents')
        ->has('widgets.own_documents.items', 2)
        ->where('widgets.own_documents.items.0.title', 'Urkunde')
        ->where('widgets.own_documents.items.0.date', '2025-01-02')
        ->where('widgets.own_documents.items.0.type_label', 'Dokument')
        ->where('widgets.own_documents.items.1.href', '/personnel/document-view?docid=ABCD-EFGH-JKLM'));
});

it('labels a document without a usable title by its kind; an editor one is never a legacy type', function () {
    $person = Personnel::factory()->create();
    Document::factory()->issued()->create(['personnel_id' => $person->id, 'title' => '', 'issued_at' => now()]);

    $this->actingAs(User::factory()->create(['personnel_id' => $person->id]))->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('widgets.own_documents.items.0.title', 'Dokument')->where('widgets.own_documents.items.0.type_label', 'Dokument'));
});

it('shows the own lists without a linked record as an empty state', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('widgets.own_documents.reason', 'no_profile')->where('widgets.own_protocols.reason', 'no_profile')
        ->where('widgets.own_incidents.reason', 'no_profile')->where('widgets.own_applications.reason', 'no_profile'));
});

it('keeps the query count constant for 5 and 30 vehicles', function () {
    $user = ht_user('vehicles.view');
    $count = function (int $n) use ($user) {
        Vehicle::query()->delete();
        $poi = Poi::factory()->create();
        foreach (range(1, $n) as $i) {
            $v = Vehicle::factory()->create(['fms_status' => (string) ($i % 7), 'fms_status_at' => now()->subMinutes($i), 'station_poi_id' => $poi->id]);
            $i % 3 === 0 && VehicleDefect::factory()->blocking()->create(['vehicle_id' => $v->id]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get('/')->assertOk();

        return count(DB::getQueryLog());
    };

    $count(1);

    expect($count(30))->toBe($count(5));
});
