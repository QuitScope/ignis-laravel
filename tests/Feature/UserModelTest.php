<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

beforeEach(fn () => $this->artisan('migrate:fresh'));

it('ranks super admin 0, role holders by priority, roleless last', function () {
    $this->seed(RoleSeeder::class);

    expect(User::factory()->superAdmin()->create()->rank())->toBe(0)
        ->and(User::factory()->withRole(Role::findByName('SGL'))->create()->rank())->toBe(100)
        ->and(User::factory()->create()->rank())->toBe(PHP_INT_MAX);
});

it('rejects a duplicate discord_id', function () {
    User::factory()->create(['discord_id' => '123456789012345678']);
    User::factory()->create(['discord_id' => '123456789012345678']);
})->throws(QueryException::class);

it('checks any-of permissions', function () {
    $role = Role::create(['name' => 'T', 'guard_name' => 'web']);
    $role->givePermissionTo(Permission::create(['name' => 'users.view']));

    $user = User::factory()->withRole($role)->create();

    expect($user->hasPermission(['users.view', 'admin']))->toBeTrue()
        ->and($user->hasPermission('users.view'))->toBeTrue()
        ->and($user->hasPermission(['admin', 'audit.view']))->toBeFalse()
        ->and(User::factory()->create()->hasPermission('users.view'))->toBeFalse()
        ->and(User::factory()->superAdmin()->create()->hasPermission('anything'))->toBeTrue();
});

it('lets the super admin pass every gate', function () {
    expect(Gate::forUser(User::factory()->superAdmin()->create())->allows('irgendwas'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('irgendwas'))->toBeFalse();
});

it('keeps exactly one default role', function () {
    $a = Role::create(['name' => 'A', 'guard_name' => 'web', 'is_default' => true]);
    $b = Role::create(['name' => 'B', 'guard_name' => 'web', 'is_default' => true]);

    expect(Role::default()?->is($b))->toBeTrue()
        ->and($a->fresh()->is_default)->toBeFalse();
});

it('scopes active users and sets a single role', function () {
    $r1 = Role::create(['name' => 'A', 'guard_name' => 'web']);
    $r2 = Role::create(['name' => 'B', 'guard_name' => 'web']);
    User::factory()->inactive()->create();
    $u = User::factory()->create();
    $u->setRole($r1);
    $u->setRole($r2);

    expect(User::active()->count())->toBe(1)
        ->and($u->fresh()->roles)->toHaveCount(1)
        ->and($u->fresh()->role()?->name)->toBe('B');
});
