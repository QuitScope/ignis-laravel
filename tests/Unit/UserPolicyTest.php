<?php

use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->policy = new UserPolicy;
    $power = Role::create(['name' => 'SGL+', 'guard_name' => 'web', 'priority' => 100, 'color' => 'primary']);
    $power->syncPermissions(['users.view', 'users.edit', 'users.delete']);
    $this->people = [
        'sa' => User::factory()->superAdmin()->create(),
        'sa2' => User::factory()->superAdmin()->create(),
        'admin' => User::factory()->withRole(Role::findByName('Admin'))->create(),
        'admin2' => User::factory()->withRole(Role::findByName('Admin'))->create(),
        'sgl' => User::factory()->withRole(Role::findByName('SGL'))->create(),
        'sgl2' => User::factory()->withRole(Role::findByName('SGL'))->create(),
        'sglDel' => User::factory()->withRole($power)->create(),
        'gast' => User::factory()->withRole(Role::findByName('Gast'))->create(),
        'norole' => User::factory()->create(),
    ];
});

dataset('rank matrix', [
    ['sa', 'admin', true, true],
    ['sa', 'sgl', true, true],
    ['sa', 'gast', true, true],
    ['sa', 'norole', true, true],
    ['sa', 'sa2', false, false],
    ['sa', 'self', false, false],
    ['admin', 'sa', false, false],
    ['admin', 'admin2', false, false],
    ['admin', 'sgl', true, true],
    ['admin', 'gast', true, true],
    ['admin', 'norole', true, true],
    ['admin', 'self', false, false],
    ['sgl', 'sa', false, false],
    ['sgl', 'admin', false, false],
    ['sgl', 'sgl2', false, false],
    ['sgl', 'gast', true, false],
    ['sgl', 'self', false, false],
    ['sglDel', 'gast', true, true],
    ['sglDel', 'sgl', false, false],
    ['sglDel', 'admin', false, false],
    ['gast', 'sa', false, false],
    ['gast', 'admin', false, false],
    ['gast', 'sgl', false, false],
    ['gast', 'norole', false, false],
    ['gast', 'self', false, false],
    ['norole', 'gast', false, false],
    ['norole', 'self', false, false],
]);

it('applies the rank matrix to update, delete and toggleActive', function (string $actor, string $target, bool $update, bool $delete) {
    $a = $this->people[$actor];
    $t = $target === 'self' ? $a : $this->people[$target];

    expect($this->policy->update($a, $t))->toBe($update)
        ->and($this->policy->delete($a, $t))->toBe($delete)
        ->and($this->policy->toggleActive($a, $t))->toBe($delete);
})->with('rank matrix');

it('restricts viewList to admin / users.view holders', function (string $actor, bool $expected) {
    expect($this->policy->viewList($this->people[$actor]))->toBe($expected);
})->with([['sa', true], ['admin', true], ['sgl', true], ['sglDel', true], ['gast', false], ['norole', false]]);

it('only lets an actor assign roles strictly below their own rank', function () {
    foreach ([
        ['sa', 'Admin', true], ['sa', 'SGL', true], ['sa', 'Gast', true],
        ['admin', 'Admin', false], ['admin', 'SGL', true], ['admin', 'Gast', true],
        ['sgl', 'Admin', false], ['sgl', 'SGL', false], ['sgl', 'TL', true], ['sgl', 'Gast', true],
        ['gast', 'Gast', false], ['gast', 'SGL', false],
        ['norole', 'Gast', false],
    ] as [$actor, $role, $expected]) {
        expect($this->policy->assignRole($this->people[$actor], Role::findByName($role)))->toBe($expected);
    }
});

it('keeps the super admin bypass for abilities that merely take a User instance', function () {
    Gate::define('tablet.revoke', fn () => false);

    expect(Gate::forUser($this->people['sa'])->allows('tablet.revoke', $this->people['gast']))->toBeTrue()
        ->and(Gate::forUser($this->people['admin'])->allows('tablet.revoke', $this->people['gast']))->toBeFalse()
        ->and(Gate::forUser($this->people['sa'])->allows('update', $this->people['sa2']))->toBeFalse();
});

it('binds a super admin by rank for the kebab-case toggle-active ability too', function () {
    $sa = $this->people['sa'];
    expect(Gate::forUser($sa)->allows('toggle-active', $this->people['sa2']))->toBeFalse()
        ->and(Gate::forUser($sa)->allows('toggle-active', $sa))->toBeFalse()
        ->and(Gate::forUser($sa)->allows('toggle-active', $this->people['admin']))->toBeTrue()
        ->and(Gate::forUser($sa)->allows('delete', $this->people['sa2']))->toBeFalse()
        ->and(Gate::forUser($sa)->allows('update', $this->people['sa2']))->toBeFalse();
});
