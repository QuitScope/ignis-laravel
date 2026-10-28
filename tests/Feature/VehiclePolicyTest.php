<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function vehicleUser(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

it('follows the legacy ability matrix', function (array $perms, array $allowed) {
    $user = vehicleUser(...$perms);

    foreach (['view', 'manage', 'createDefect', 'deleteDefect', 'manageImport'] as $ability) {
        expect(Gate::forUser($user)->allows('vehicle.'.$ability))->toBe(in_array($ability, $allowed, true), $ability);
    }
})->with([
    'none' => [[], []],
    'view' => [['vehicles.view'], ['view', 'createDefect']],
    'manage' => [['vehicles.manage'], ['manage', 'createDefect', 'manageImport']],
    'view+manage' => [['vehicles.view', 'vehicles.manage'], ['view', 'manage', 'createDefect', 'manageImport']],
    'admin' => [['admin'], ['view', 'manage', 'createDefect', 'deleteDefect', 'manageImport']],
]);

it('lets a super admin pass every vehicle ability', function () {
    $user = User::factory()->superAdmin()->create();

    foreach (['view', 'manage', 'createDefect', 'deleteDefect', 'manageImport'] as $ability) {
        expect(Gate::forUser($user)->allows('vehicle.'.$ability))->toBeTrue();
    }
});
