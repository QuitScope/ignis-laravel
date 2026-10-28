<?php

use App\Auth\Permissions;
use App\Models\Role;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Policies\RolePolicy;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function userWith(string ...$perms): User
{
    $role = Role::create(['name' => 'T'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function payload(array $over = []): array
{
    return array_merge(['name' => 'Neu', 'priority' => 300, 'color' => 'success', 'permissions' => ['users.view']], $over);
}

it('redirects guests to /login', function () {
    $this->get('/users/roles')->assertRedirect('/login');
});

it('shows the list to users.view and admin holders, 403 otherwise', function () {
    $this->actingAs(userWith('users.view'))->get('/users/roles')->assertOk();
    $this->actingAs(userWith('admin'))->get('/users/roles')->assertOk();
    $this->actingAs(userWith('audit.view'))->get('/users/roles')->assertForbidden();
});

it('passes roles with users_count and permissions plus only the catalog to the page', function () {
    Permission::create(['name' => 'module.secret', 'guard_name' => 'web']);
    $this->actingAs(userWith('users.view'))->get('/users/roles')
        ->assertInertia(fn (Assert $p) => $p
            ->component('Roles/Index')
            ->has('roles', 8)
            ->has('roles.0', fn (Assert $r) => $r->hasAll(['id', 'name', 'priority', 'color', 'is_default', 'users_count', 'permissions']))
            ->where('permissionGroups', fn ($g) => collect($g)->flatten()->contains('users.view') && ! collect($g)->flatten()->contains('module.secret'))
            ->where('can.manage', false));
});

it('lets a super admin create a role with permissions', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->post('/users/roles', payload(['permissions' => ['users.view', 'audit.view']]))
        ->assertRedirect();

    $role = Role::findByName('Neu');
    expect($role->priority)->toBe(300)->and($role->color)->toBe('success')
        ->and($role->is_default)->toBeFalse()
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['audit.view', 'users.view']);
});

it('forbids a normal admin to create, update and delete', function () {
    $admin = userWith('admin');
    $role = Role::findByName('TL');

    $this->actingAs($admin)->post('/users/roles', payload())->assertForbidden();
    $this->actingAs($admin)->put("/users/roles/{$role->id}", payload())->assertForbidden();
    $this->actingAs($admin)->delete("/users/roles/{$role->id}")->assertForbidden();
    expect(Role::where('name', 'Neu')->exists())->toBeFalse()->and($role->fresh())->not->toBeNull();
});

it('rejects invalid input', function (array $over, string $field) {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->postJson('/users/roles', payload($over))->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'priority 0' => [['priority' => 0], 'priority'],
    'priority 1000' => [['priority' => 1000], 'priority'],
    'bad colour' => [['color' => 'pink'], 'color'],
    'unknown permission' => [['permissions' => ['nope.nothing']], 'permissions.0'],
    'duplicate name' => [['name' => 'Admin'], 'name'],
    'duplicate name, other case' => [['name' => 'aDMIN'], 'name'],
]);

it('updates a role, keeps its own name valid, and changes nothing on an unknown permission', function () {
    $su = User::factory()->superAdmin()->create();
    $role = Role::findByName('TL');

    $this->actingAs($su)->putJson("/users/roles/{$role->id}", payload(['name' => 'TL', 'permissions' => ['x.y']]))
        ->assertStatus(422);
    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['personnel.documents.manage', 'personnel.view']);

    $this->actingAs($su)->put("/users/roles/{$role->id}", payload(['name' => 'TL', 'priority' => 111, 'permissions' => ['vehicles.view']]))->assertRedirect();
    expect($role->fresh()->priority)->toBe(111)->and($role->fresh()->permissions->pluck('name')->all())->toBe(['vehicles.view']);
});

it('does not touch is_default through the form', function () {
    $su = User::factory()->superAdmin()->create();
    $gast = Role::default();

    $this->actingAs($su)->put("/users/roles/{$gast->id}", payload(['name' => 'Gast', 'is_default' => false]))->assertRedirect();
    expect($gast->fresh()->is_default)->toBeTrue();
});

it('applies changed role permissions on the next request', function () {
    $user = userWith('users.view');
    $role = $user->role();
    $this->actingAs($user)->get('/users/roles')->assertOk();

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put("/users/roles/{$role->id}", payload(['name' => $role->name, 'permissions' => ['audit.view']]))->assertRedirect();

    $this->actingAs($user->fresh())->get('/users/roles')->assertForbidden();
});

it('deletes an unused role', function () {
    $role = Role::findByName('TL');
    $this->actingAs(User::factory()->superAdmin()->create())->delete("/users/roles/{$role->id}")->assertRedirect();
    expect(Role::where('name', 'TL')->exists())->toBeFalse();
});

it('refuses to delete a role with users or the default role', function () {
    $su = User::factory()->superAdmin()->create();
    $used = Role::findByName('SGL');
    User::factory()->withRole($used)->create();

    $this->actingAs($su)->deleteJson("/users/roles/{$used->id}")->assertStatus(422);
    $this->actingAs($su)->deleteJson('/users/roles/'.Role::default()->id)->assertStatus(422);
    expect(Role::count())->toBe(7);
});

it('rejects duplicate permissions in a role payload', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->postJson('/users/roles', payload(['permissions' => ['users.view', 'users.view']]))
        ->assertStatus(422)->assertJsonValidationErrors('permissions.1');
});

it('registers the policy for the Spatie subclass (403 comes from authorize())', function () {
    expect(Gate::policies()[Role::class] ?? null)->toBe(RolePolicy::class)
        ->and(Gate::getPolicyFor(Role::class))->toBeInstanceOf(RolePolicy::class);
});

it('groups exactly the catalog permissions', function () {
    $grouped = collect(Permissions::groups())->flatten()->sort()->values()->all();
    $catalog = collect(array_keys(Permissions::catalog()))->sort()->values()->all();

    expect($grouped)->toBe($catalog);
});

it('lets a super admin edit a role holding imported non-catalog permissions', function () {
    $role = Role::create(['name' => 'Imp', 'guard_name' => 'web', 'priority' => 400, 'color' => 'info']);
    $role->syncPermissions([Permission::create(['name' => 'oldplugin.view', 'guard_name' => 'web'])]);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put("/users/roles/{$role->id}", payload(['name' => 'Imp', 'permissions' => ['oldplugin.view', 'audit.view']]))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['audit.view', 'oldplugin.view']);
});

it('rejects non-catalog permissions on create and unheld unknown ones on update', function () {
    $sa = User::factory()->superAdmin()->create();
    Permission::create(['name' => 'oldplugin.view', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'Imp', 'guard_name' => 'web', 'priority' => 400, 'color' => 'info']);

    $this->actingAs($sa)->post('/users/roles', payload(['permissions' => ['oldplugin.view']]))
        ->assertSessionHasErrors('permissions.0');
    $this->actingAs($sa)->put("/users/roles/{$role->id}", payload(['name' => 'Imp', 'permissions' => ['oldplugin.view']]))
        ->assertSessionHasErrors('permissions.0');
});

it('answers 403 before 422 for a non-super-admin with an invalid body', function () {
    $role = Role::create(['name' => 'Imp', 'guard_name' => 'web', 'priority' => 400, 'color' => 'info']);
    $admin = User::factory()->withRole(Role::findByName('Admin'))->create();

    $this->actingAs($admin)->put("/users/roles/{$role->id}", ['name' => ''])->assertForbidden();
    $this->actingAs($admin)->post('/users/roles', ['name' => ''])->assertForbidden();
});

function withDemoModule(bool $on): void
{
    $modules = new ModuleRegistry(base_path('tests/Fixtures/modules'), app(Settings::class));
    app()->instance(ModuleRegistry::class, $modules);
    $modules->registerAll(app());
    app(Settings::class)->set('modules.demo.enabled', $on);
}

it('offers a module group in the editor only while the module is on', function () {
    withDemoModule(true);
    $this->actingAs(userWith('users.view'))->get('/users/roles')
        ->assertInertia(fn (Assert $p) => $p->where('permissionGroups.demo', ['demo.manage']));

    app(Settings::class)->set('modules.demo.enabled', false);
    $this->actingAs(userWith('users.view'))->get('/users/roles')
        ->assertInertia(fn (Assert $p) => $p->missing('permissionGroups.demo'));
});

it('keeps the permissions of a switched-off module on a role without showing them', function () {
    withDemoModule(true);
    $su = User::factory()->superAdmin()->create();
    $this->actingAs($su)->post('/users/roles', payload(['permissions' => ['users.view', 'demo.manage']]))->assertSessionHasNoErrors();
    $role = Role::findByName('Neu');

    app(Settings::class)->set('modules.demo.enabled', false);
    $this->actingAs($su)->get('/users/roles')->assertInertia(fn (Assert $p) => $p
        ->where('roles', fn ($roles) => collect($roles)->firstWhere('name', 'Neu')['permissions'] === ['users.view']));

    $this->actingAs($su)->put("/users/roles/{$role->id}", payload(['permissions' => ['audit.view']]))->assertSessionHasNoErrors();
    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['audit.view', 'demo.manage']);
    $this->actingAs($su)->put("/users/roles/{$role->id}", payload(['permissions' => ['audit.view', 'demo.manage']]))->assertSessionHasNoErrors();
    expect($role->fresh()->permissions->pluck('name')->sort()->values()->all())->toBe(['audit.view', 'demo.manage']);
});

it('refuses switched-off module permissions a role does not hold yet, and unknown ones', function () {
    withDemoModule(false);
    $su = User::factory()->superAdmin()->create();

    $this->actingAs($su)->postJson('/users/roles', payload(['permissions' => ['demo.manage']]))->assertStatus(422)->assertJsonValidationErrors('permissions.0');
    $this->actingAs($su)->postJson('/users/roles', payload(['permissions' => ['made.up']]))->assertStatus(422)->assertJsonValidationErrors('permissions.0');
    $role = Role::findByName('Gast');
    $this->actingAs($su)->putJson("/users/roles/{$role->id}", payload(['name' => 'Gast', 'permissions' => ['demo.manage']]))->assertStatus(422);
});

it('leaves switched-off module permissions out of the shared permission list', function () {
    withDemoModule(true);
    Permission::create(['name' => 'demo.manage', 'guard_name' => 'web']);
    $user = userWith('users.view', 'demo.manage');
    $this->actingAs($user)->get('/users/roles')->assertInertia(fn (Assert $p) => $p->where('auth.user.permissions', ['demo.manage', 'users.view']));
    $this->actingAs(User::factory()->superAdmin()->create())->get('/users/roles')
        ->assertInertia(fn (Assert $p) => $p->where('auth.user.permissions', fn ($perms) => collect($perms)->contains('demo.manage')));

    app(Settings::class)->set('modules.demo.enabled', false);
    $this->actingAs($user)->get('/users/roles')->assertInertia(fn (Assert $p) => $p->where('auth.user.permissions', ['users.view']));
    $this->actingAs(User::factory()->superAdmin()->create())->get('/users/roles')
        ->assertInertia(fn (Assert $p) => $p->where('auth.user.permissions', fn ($perms) => ! collect($perms)->contains('demo.manage')));
});
