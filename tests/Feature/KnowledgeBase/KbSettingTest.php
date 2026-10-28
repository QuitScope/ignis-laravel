<?php

use App\Models\Role;
use App\Models\User;
use App\Support\SettingDefinitions;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function kbs_admin(): User
{
    $role = Role::create(['name' => 'S'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);

    return User::factory()->withRole($role)->create();
}

it('registers knowledgebase.public_access as a bool in group features, default off', function () {
    $d = app(SettingDefinitions::class)->definition('knowledgebase.public_access');
    expect($d)->not->toBeNull()->and($d->type)->toBe('bool')->and($d->group)->toBe('features')->and($d->module)->toBe('knowledgebase')
        ->and(app(Settings::class)->get('knowledgebase.public_access'))->toBeFalse();
});

it('shows the setting in the features tab only while the module is on', function () {
    $keys = fn () => array_map(fn ($d) => $d->key, app(SettingDefinitions::class)->group('features'));
    expect($keys())->toContain('knowledgebase.public_access');
    app(Settings::class)->set('modules.knowledgebase.enabled', false);
    expect($keys())->not->toContain('knowledgebase.public_access');
});

it('saves it through the configuration form, and refuses it with the module off', function () {
    $admin = kbs_admin();
    $this->actingAs($admin)->put('/settings/system/config/features', ['knowledgebase.public_access' => true])->assertSessionDoesntHaveErrors();
    expect(app(Settings::class)->get('knowledgebase.public_access'))->toBeTrue();

    app(Settings::class)->set('modules.knowledgebase.enabled', false);
    $this->actingAs($admin)->put('/settings/system/config/features', ['knowledgebase.public_access' => false])->assertSessionHasErrors('knowledgebase.public_access');
    expect(app(Settings::class)->get('knowledgebase.public_access'))->toBeTrue();
});
