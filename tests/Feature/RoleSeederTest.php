<?php

use App\Models\Role;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->artisan('migrate:fresh');
    $this->seed(RoleSeeder::class);
});

it('seeds the 7 legacy roles', function () {
    expect(Role::count())->toBe(7)
        ->and(Role::where('is_default', true)->pluck('name')->all())->toBe(['Gast'])
        ->and(Role::findByName('Admin')->priority)->toBe(10)
        ->and(Role::findByName('Admin')->hasPermissionTo('admin'))->toBeTrue()
        ->and(Role::findByName('SGL')->permissions)->toHaveCount(11);
});

it('is idempotent', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::count())->toBe(7);
});
