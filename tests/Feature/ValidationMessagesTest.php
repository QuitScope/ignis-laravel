<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function vm_post(array $over): array
{
    $role = Role::create(['name' => 'V', 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['vehicles.manage', 'vehicles.view']);
    $input = $over + ['name' => 'RTW 1', 'type' => 'RTW', 'identifier' => 'rtw_1', 'service' => 2];

    return test()->actingAs(User::factory()->withRole($role)->create())->postJson('/vehicles', $input)->assertStatus(422)->json('errors');
}

it('shows real German validation messages with German attribute names', function () {
    config(['app.locale' => 'de']);
    $errors = vm_post(['name' => '', 'station_poi_id' => 99999]);

    expect($errors['name'][0])->toBe('Name ist erforderlich.')
        ->and($errors['station_poi_id'][0])->toBe('Die Auswahl für Wache ist ungültig.');
});

it('shows real English validation messages with English attribute names', function () {
    config(['app.locale' => 'en']);
    $errors = vm_post(['name' => '', 'station_poi_id' => 99999]);

    expect($errors['name'][0])->toBe('The name field is required.')
        ->and($errors['station_poi_id'][0])->toBe('The selected station is invalid.');
});
