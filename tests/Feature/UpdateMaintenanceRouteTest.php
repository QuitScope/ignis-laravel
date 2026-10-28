<?php

use App\Models\Role;
use App\Models\User;
use App\Update\Maintenance;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\MaintenanceSandbox;

uses(RefreshDatabase::class);

beforeEach(function () {
    MaintenanceSandbox::enter();
    $this->seed(RoleSeeder::class);
    Route::middleware(['web', 'auth', 'can:system.admin'])->get('settings/system/update/status', fn () => response()->json(['ok' => true]));
    Route::middleware(['web', 'auth'])->get('dummy-other', fn () => 'other');
});

it('status route reachable in maintenance mode for admin only', function () {
    $role = Role::create(['name' => 'A'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);
    $admin = User::factory()->withRole($role)->create();

    (new Maintenance)->enable();

    $this->actingAs($admin)->get('/settings/system/update/status')->assertOk()->assertJson(['ok' => true]);
    $this->actingAs($admin)->get('/dummy-other')->assertStatus(503);
    auth()->logout();
    expect($this->get('/settings/system/update/status')->status())->toBeIn([302, 401]);
});
