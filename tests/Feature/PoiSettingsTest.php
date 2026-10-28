<?php

use App\Models\Poi;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function poiUser(string ...$perms): User
{
    $role = Role::create(['name' => 'P'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function poiPayload(array $over = []): array
{
    return $over + ['name' => 'Klinik Nord', 'city' => 'Stadt', 'type' => 'Klinik', 'legacy_identifier' => 'kn', 'is_active' => true];
}

it('keeps the identifier unique and limited to 100 characters, and gates by pois.*', function () {
    $manager = poiUser('pois.manage');
    $this->actingAs($manager)->post('/settings/pois', poiPayload())->assertSessionHasNoErrors();
    $this->actingAs($manager)->post('/settings/pois', poiPayload(['name' => 'Zwei']))->assertSessionHasErrors('legacy_identifier');
    $this->actingAs($manager)->post('/settings/pois', poiPayload(['name' => 'Drei', 'legacy_identifier' => str_repeat('x', 101)]))->assertSessionHasErrors('legacy_identifier');
    $poi = Poi::query()->where('legacy_identifier', 'kn')->firstOrFail();
    $this->actingAs($manager)->put("/settings/pois/{$poi->id}", poiPayload(['name' => 'Umbenannt']))->assertSessionHasNoErrors();

    $viewer = poiUser('pois.view');
    $this->actingAs($viewer)->get('/settings/pois')->assertOk();
    $this->actingAs($viewer)->post('/settings/pois', poiPayload(['legacy_identifier' => 'zz']))->assertForbidden();
    $this->actingAs(poiUser())->get('/settings/pois')->assertForbidden();
});

it('counts Krankenhaus and Klinik as hospitals, active only', function () {
    Poi::factory()->create(['type' => 'Krankenhaus', 'name' => 'A']);
    Poi::factory()->create(['type' => 'Klinik', 'name' => 'B']);
    Poi::factory()->create(['type' => 'Klinik', 'is_active' => false]);
    Poi::factory()->create(['type' => 'Rettungswache']);

    expect(Poi::hospitals()->orderBy('name')->pluck('name')->all())->toBe(['A', 'B']);
});

it('redirects the old target administration to the POI settings', function () {
    $this->get('/enotf/admin/zielverwaltung/create')->assertStatus(301)->assertRedirect('/settings/pois');
});
