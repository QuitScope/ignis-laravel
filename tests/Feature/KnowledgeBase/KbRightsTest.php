<?php

use App\Auth\Permissions;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\KnowledgeBase\Models\KbEntry;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function kbr_user(string ...$perms): User
{
    $role = Role::create(['name' => 'R'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

function kbr_entry(bool $archived = false): KbEntry
{
    return tap((new KbEntry)->forceFill(['type' => 'general', 'title' => 'T', 'search_text' => 't', 'is_archived' => $archived]))->save();
}

it('registers kb.edit and kb.archive in group knowledgebase, and no kb.view', function () {
    expect(Permissions::groups())->toHaveKey('knowledgebase')->and(Permissions::groups()['knowledgebase'])->toBe(['kb.edit', 'kb.archive'])
        ->and(Permissions::known())->not->toContain('kb.view')
        ->and(Permissions::moduleOf('kb.edit'))->toBe('knowledgebase');
});

it('lists the permissions in the role editor only while the module is on', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->get('/users/roles')->assertInertia(fn (Assert $p) => $p->has('permissionGroups.knowledgebase'));
    app(Settings::class)->set('modules.knowledgebase.enabled', false);
    expect(Permissions::groups())->not->toHaveKey('knowledgebase')->and(Permissions::known())->toContain('kb.edit', 'kb.archive');
    $this->actingAs($admin)->get('/users/roles')->assertInertia(fn (Assert $p) => $p->missing('permissionGroups.knowledgebase'));
});

it('reads: guests only with public access and not archived, accounts any but archived only with kb.archive or admin', function () {
    $open = kbr_entry();
    $old = kbr_entry(true);
    $plain = kbr_user('audit.view');
    $archiver = kbr_user('kb.archive');
    $admin = kbr_user('admin');

    expect(Gate::forUser(null)->allows('read', $open))->toBeFalse();
    app(Settings::class)->set('knowledgebase.public_access', true);
    expect(Gate::forUser(null)->allows('read', $open))->toBeTrue()->and(Gate::forUser(null)->allows('read', $old))->toBeFalse();
    app(Settings::class)->set('knowledgebase.public_access', false);

    foreach ([$plain, $archiver, $admin] as $u) {
        expect($u->can('read', $open))->toBeTrue();
    }
    expect($plain->can('read', $old))->toBeFalse()->and($archiver->can('read', $old))->toBeTrue()->and($admin->can('read', $old))->toBeTrue();
});

it('edit, archive and hideEditor follow kb.edit, kb.archive and admin', function () {
    $e = kbr_entry();
    $editor = kbr_user('kb.edit');
    $archiver = kbr_user('kb.archive');
    $admin = kbr_user('admin');
    $none = kbr_user('audit.view');

    expect($editor->can('edit', $e))->toBeTrue()->and($editor->can('archive', $e))->toBeFalse()->and($editor->can('hideEditor', $e))->toBeFalse()
        ->and($archiver->can('archive', $e))->toBeTrue()->and($archiver->can('edit', $e))->toBeFalse()
        ->and($admin->can('edit', $e))->toBeTrue()->and($admin->can('archive', $e))->toBeTrue()->and($admin->can('hideEditor', $e))->toBeTrue()
        ->and($none->can('edit', $e))->toBeFalse()->and($none->can('archive', $e))->toBeFalse()->and($none->can('read', $e))->toBeTrue();
});

it('denies every ability with the module switched off', function () {
    $e = kbr_entry();
    $editor = kbr_user('kb.edit', 'kb.archive', 'admin');
    app(Settings::class)->set('modules.knowledgebase.enabled', false);
    app(Settings::class)->set('knowledgebase.public_access', true);
    foreach (['read', 'edit', 'archive', 'hideEditor'] as $ability) {
        expect($editor->can($ability, $e))->toBeFalse();
    }
    expect(Gate::forUser(null)->allows('read', $e))->toBeFalse();
});

it('puts the module into app.modules for accounts', function () {
    $this->actingAs(kbr_user('audit.view'))->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->where('app.modules', fn ($m) => collect($m)->contains('knowledgebase')));
});
