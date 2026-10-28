<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ManvBoard\Enums\ManvIncidentStatus;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function mr_user(string ...$perms): User
{
    $role = Role::create(['name' => 'M'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function mr_incident(ManvIncidentStatus $status = ManvIncidentStatus::Active): ManvIncident
{
    $i = (new ManvIncident)->forceFill(['incident_number' => 'L-1', 'location' => 'Halle', 'started_at' => now(), 'status' => $status]);
    $i->save();

    return $i;
}

it('sends guests to the login', function () {
    $this->get('/mci')->assertRedirect('/login');
});

it('lets view and manage read the list and keeps others out', function () {
    $this->actingAs(mr_user('logbook.view'))->get('/mci')->assertForbidden();
    $this->actingAs(mr_user('mci.view'))->get('/mci')->assertOk();
    $this->actingAs(mr_user('mci.manage'))->get('/mci')->assertOk();
    $this->actingAs(mr_user('admin'))->get('/mci')->assertOk();
    $this->actingAs(User::factory()->superAdmin()->create())->get('/mci')->assertOk();
});

it('grades the policy: view reads, manage writes, admin and super admin do everything', function () {
    $i = mr_incident();
    $none = mr_user('audit.view');
    $viewer = mr_user('mci.view');
    $manager = mr_user('mci.manage');
    $admin = mr_user('admin');
    $super = User::factory()->superAdmin()->create();

    foreach (['viewAny' => ManvIncident::class, 'view' => $i] as $ability => $arg) {
        expect($none->can($ability, $arg))->toBeFalse();
        foreach ([$viewer, $manager, $admin, $super] as $u) {
            expect($u->can($ability, $arg))->toBeTrue();
        }
    }
    foreach (['create' => ManvIncident::class, 'update' => $i, 'delete' => $i] as $ability => $arg) {
        foreach ([$none, $viewer] as $u) {
            expect($u->can($ability, $arg))->toBeFalse();
        }
        foreach ([$manager, $admin, $super] as $u) {
            expect($u->can($ability, $arg))->toBeTrue();
        }
    }
});

it('treats only an active Lage as writable', function () {
    $policy = new ManvIncidentPolicy;

    expect($policy->isWritable(mr_incident()))->toBeTrue()
        ->and($policy->isWritable(mr_incident(ManvIncidentStatus::Closed)))->toBeFalse()
        ->and($policy->isWritable(mr_incident(ManvIncidentStatus::Archived)))->toBeFalse();
});

it('puts the module into app.modules so the navigation entry can show for view', function () {
    $this->actingAs(mr_user('mci.view'))->get('/dashboard')->assertInertia(fn ($p) => $p
        ->where('app.modules', fn ($m) => collect($m)->contains('manvboard'))
        ->where('auth.user.permissions', fn ($perms) => collect($perms)->contains('mci.view')));
});
