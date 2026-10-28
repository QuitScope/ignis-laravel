<?php

namespace Database\Seeders;

use App\Auth\Permissions;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    private const ROLES = [
        'Admin' => [10, 'danger', ['admin']],
        'SGL' => [100, 'primary', ['application.view', 'application.edit', 'edivi.view', 'personnel.view', 'personnel.edit', 'personnel.documents.manage', 'users.view', 'users.edit', 'users.create', 'files.upload', 'files.log.view']],
        'TL' => [110, 'primary', ['personnel.view', 'personnel.documents.manage']],
        'QM-RD' => [200, 'info', ['personnel.view', 'edivi.view', 'edivi.edit']],
        'Ausbilder' => [210, 'success', ['personnel.view', 'personnel.documents.manage']],
        'Personaler' => [220, 'success', ['personnel.view', 'personnel.edit', 'personnel.documents.manage']],
        'Gast' => [999, 'secondary', []],
    ];

    public function run(): void
    {
        foreach (Permissions::known() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::ROLES as $name => [$priority, $color, $permissions]) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], [
                'priority' => $priority,
                'color' => $color,
                'is_default' => $name === 'Gast',
            ]);
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
