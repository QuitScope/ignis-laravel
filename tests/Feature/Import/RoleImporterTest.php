<?php

use App\Auth\Permissions;
use App\Models\Role;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

/** @return array<string, mixed> */
function importRoles(bool $dry = false): array
{
    $code = Artisan::call('ignis:import-legacy', ['--only' => ['role'], '--format' => 'json'] + ($dry ? ['--dry-run' => true] : []));
    $json = json_decode(Artisan::output(), true);
    expect($code)->toBe(0);

    return $json;
}

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    LegacySeed::roles();
    $this->seed(RoleSeeder::class);
});

it('adopts seeded same-name roles, creates new ones and maps ids', function () {
    DB::table('roles')->where('name', 'Gast')->update(['priority' => 1, 'color' => 'x']);
    $before = Role::count();

    $json = importRoles();

    $sgl = Role::findByName('SGL');
    expect($json['entities']['role'])->toBe(['read' => 3, 'written' => 3, 'skipped' => 0, 'failed' => 0])
        ->and(Role::count())->toBe($before)
        ->and($sgl->priority)->toBe(100)
        ->and($sgl->permissions->pluck('name')->sort()->values()->all())->toBe(['edivi.view', 'legacy.custom', 'users.view'])
        ->and(Role::findByName('Gast')->priority)->toBe(999)
        ->and(Role::findByName('Gast')->color)->toBe('secondary')
        ->and(Role::findByName('Gast')->is_default)->toBeTrue()
        ->and(DB::table('legacy_id_map')->where('entity', 'role')->count())->toBe(3)
        ->and(Permission::where('name', 'legacy.custom')->exists())->toBeTrue()
        ->and(collect($json['notes'])->join(' '))->toContain('adopted existing role SGL')
        ->and(collect($json['warnings'])->filter(fn ($w) => str_contains($w, 'legacy.custom')))->toHaveCount(1)
        ->and(collect($json['warnings'])->join(' '))->toContain('TL');
});

it('creates roles that do not exist yet', function () {
    DB::table('roles')->delete();

    importRoles();

    expect(Role::count())->toBe(3)->and(Role::default()->name)->toBe('Gast');
});

it('is idempotent', function () {
    importRoles();
    $roles = Role::count();
    $json = importRoles();

    expect(Role::count())->toBe($roles)
        ->and(DB::table('legacy_id_map')->count())->toBe(3)
        ->and(collect($json['warnings'])->filter(fn ($w) => str_contains($w, 'legacy.custom')))->toHaveCount(0);
});

it('dry run leaves no changes', function () {
    $before = [Role::count(), Permission::count(), Role::findByName('SGL')->priority];

    $json = importRoles(dry: true);

    expect([Role::count(), Permission::count(), Role::findByName('SGL')->priority])->toBe($before)
        ->and(DB::table('legacy_id_map')->count())->toBe(0)
        ->and($json['entities']['role']['written'])->toBe(3);
});

it('keeps invalid permission json as a role without permissions plus a warning', function () {
    LegacyDatabase::connection()->table('intra_users_roles')->where('id', 2)->update(['permissions' => '{oops']);

    $json = importRoles();

    expect(Role::findByName('SGL')->permissions)->toHaveCount(0)
        ->and(collect($json['warnings'])->join(' '))->toContain('role 2');
});

it('clamps priority to 1-999 and defaults the color', function () {
    $db = LegacyDatabase::connection();
    $db->table('intra_users_roles')->where('id', 1)->update(['priority' => 0, 'color' => '']);
    $db->table('intra_users_roles')->where('id', 2)->update(['priority' => 5000]);

    $json = importRoles();

    expect(Role::findByName('Admin')->priority)->toBe(1)
        ->and(Role::findByName('Admin')->color)->toBe('secondary')
        ->and(Role::findByName('SGL')->priority)->toBe(999)
        ->and(collect($json['warnings'])->filter(fn ($w) => str_contains($w, 'priority')))->toHaveCount(2);
});

it('suffixes duplicate legacy names', function () {
    LegacySeed::roles([['id' => 4, 'name' => 'sgl'], ['id' => 5, 'name' => 'SGL']]);

    $json = importRoles();

    expect(Role::pluck('name')->all())->toContain('SGL', 'sgl (2)', 'SGL (3)')
        ->and(collect($json['warnings'])->join(' '))->toContain('sgl (2)');
});

it('keeps the default of the smallest legacy id when several are default', function () {
    $db = LegacyDatabase::connection();
    $db->table('intra_users_roles')->where('id', 1)->update(['default' => 1]);

    $json = importRoles();

    expect(Role::where('is_default', true)->pluck('name')->all())->toBe(['Admin'])
        ->and(collect($json['warnings'])->join(' '))->toContain('default');
});

it('warns when an admin-flagged role lacks the admin permission', function () {
    LegacyDatabase::connection()->table('intra_users_roles')->where('id', 2)->update(['admin' => 1]);

    $json = importRoles();

    expect(collect($json['warnings'])->join(' '))->toContain('SGL')->toContain('admin permission');
});

it('makes new permissions effective in the same process', function () {
    $user = User::factory()->withRole(Role::findByName('SGL'))->create();
    expect($user->fresh()->hasPermissionTo('users.view'))->toBeTrue()
        ->and($user->fresh()->hasPermissionTo('edivi.edit'))->toBeFalse();

    importRoles();

    $user = $user->fresh();
    expect($user->hasPermissionTo('legacy.custom'))->toBeTrue()
        ->and($user->hasPermissionTo('edivi.view'))->toBeTrue()
        ->and($user->hasPermissionTo('application.edit'))->toBeFalse();
});

it('picks a free suffix when the suffixed name already exists', function () {
    Role::create(['name' => 'SGL (2)', 'guard_name' => 'web', 'priority' => 500, 'color' => 'x', 'is_default' => false]);
    LegacySeed::roles([['id' => 4, 'name' => 'SGL']]);

    $json = importRoles();

    expect(Role::pluck('name')->all())->toContain('SGL', 'SGL (2)', 'SGL (2) (2)')
        ->and($json['entities']['role']['failed'])->toBe(0);
});

it('survives legacy names SGL, SGL and SGL (2)', function () {
    LegacySeed::roles([['id' => 4, 'name' => 'SGL'], ['id' => 5, 'name' => 'SGL (2)']]);

    $json = importRoles();

    expect($json['entities']['role'])->toBe(['read' => 5, 'written' => 5, 'skipped' => 0, 'failed' => 0])
        ->and(Role::where('name', 'like', 'SGL%')->count())->toBe(3)
        ->and(importRoles()['entities']['role']['failed'])->toBe(0)
        ->and(Role::where('name', 'like', 'SGL%')->count())->toBe(3);
});

it('resolves a legacy role renamed onto another mapped role between runs', function () {
    importRoles();
    LegacyDatabase::connection()->table('intra_users_roles')->where('id', 2)->update(['name' => 'Admin']);

    $json = importRoles();

    expect($json['entities']['role']['failed'])->toBe(0)
        ->and(Role::pluck('name')->all())->toContain('Admin', 'Admin (2)')
        ->and(Role::count())->toBe(7);
});

it('retries with the next suffix after a unique violation', function () {
    $thrown = false;
    Role::saving(function (Role $role) use (&$thrown) {
        if (! $thrown && ! $role->exists && $role->name === 'Brand New') {
            $thrown = true;
            throw new UniqueConstraintViolationException('sqlite', 'insert', [], new Exception('dup'));
        }
    });
    LegacySeed::roles([['id' => 4, 'name' => 'Brand New']]);

    $json = importRoles();

    expect($thrown)->toBeTrue()
        ->and(Role::where('name', 'Brand New (2)')->exists())->toBeTrue()
        ->and($json['entities']['role']['failed'])->toBe(0);
});

it('writes the adoption note only when the row succeeded', function () {
    Role::saving(function (Role $role) {
        if ($role->name === 'SGL') {
            throw new RuntimeException('row broke');
        }
    });

    $code = Artisan::call('ignis:import-legacy', ['--only' => ['role'], '--format' => 'json']);
    $json = json_decode(Artisan::output(), true);

    expect($code)->toBe(1)
        ->and($json['entities']['role']['failed'])->toBe(1)
        ->and(collect($json['notes'])->join(' '))->not->toContain('SGL')
        ->and(collect($json['notes'])->join(' '))->toContain('Admin');
});

it('never writes to the legacy database', function () {
    $count = LegacyDatabase::connection()->table('intra_users_roles')->count();

    importRoles();

    expect(LegacyDatabase::connection()->table('intra_users_roles')->count())->toBe($count);
});

it('reports only permissions no module registers as unknown', function () {
    $modules = new ModuleRegistry(base_path('tests/Fixtures/modules'), app(Settings::class));
    app()->instance(ModuleRegistry::class, $modules);
    $modules->registerAll(app());
    app(Settings::class)->set('modules.demo.enabled', false);
    Permissions::register('kb.edit', 'knowledgebase', 'knowledgebase');
    LegacyDatabase::connection()->table('intra_users_roles')->where('id', 2)
        ->update(['permissions' => '["users.view","kb.edit","demo.manage","oldplugin.view"]']);

    $json = importRoles();

    $unknown = collect($json['warnings'])->first(fn (string $w) => str_contains($w, 'permission(s) unknown'));
    expect($unknown)->toContain('oldplugin.view')->not->toContain('kb.edit')->not->toContain('demo.manage')
        ->and(Role::findByName('SGL')->permissions->pluck('name')->sort()->values()->all())->toBe(['demo.manage', 'kb.edit', 'oldplugin.view', 'users.view']);
});

it('does not create the dropped kb.view permission', function () {
    LegacyDatabase::connection()->table('intra_users_roles')->where('id', 2)->update(['permissions' => '["users.view","kb.view"]']);

    $json = importRoles();

    expect(Role::findByName('SGL')->permissions->pluck('name')->all())->toBe(['users.view'])
        ->and(Permission::where('name', 'kb.view')->exists())->toBeFalse()
        ->and(implode(' ', $json['warnings']))->not->toContain('kb.view');
});
