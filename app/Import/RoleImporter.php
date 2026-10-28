<?php

namespace App\Import;

use App\Auth\Permissions;
use App\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * intra_users_roles => roles. Legacy is the source of truth for priority, color, default flag
 * and permissions of every role it knows. Seeded roles with the same name are adopted.
 */
final class RoleImporter implements Importer
{
    private const ENTITY = 'role';

    private const DROPPED = ['kb.view'];

    private const ALIASES = ['manv.manage' => 'mci.manage'];

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function requiredTables(): array
    {
        return ['intra_users_roles'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $rows = $ctx->legacy()->table('intra_users_roles')->orderBy('id')->get();

        /** @var array<string, true> $known */
        $known = Permission::where('guard_name', 'web')->pluck('name')->flip()->map(fn () => true)->all();
        /** @var array<int, string> $taken new role id => legacy id */
        $taken = array_flip($ctx->ids()->forEntity(self::ENTITY));
        /** @var array<string, int> $nameCount lowercase legacy name => rows seen */
        $nameCount = [];
        $createdPermissions = [];
        $defaultLegacyId = null;

        try {
            DB::transaction(function () use ($ctx, $report, $rows, &$known, &$taken, &$nameCount, &$createdPermissions, &$defaultLegacyId) {
                foreach ($rows as $row) {
                    $report->read(self::ENTITY);
                    $legacyId = (string) $row->id;
                    $base = trim((string) $row->name) ?: "Role {$legacyId}";
                    $nameCount[mb_strtolower($base)] = ($nameCount[mb_strtolower($base)] ?? 0) + 1;
                    $count = $nameCount[mb_strtolower($base)];
                    $name = $count > 1 ? "{$base} ({$count})" : $base;

                    try {
                        $result = DB::transaction(fn () => $this->importRow($ctx, $row, $base, $name, $count > 1, $taken, $known, $defaultLegacyId));
                    } catch (Throwable $e) {
                        $report->failed(self::ENTITY, "legacy role {$legacyId}: ".$e->getMessage());
                        app(PermissionRegistrar::class)->forgetCachedPermissions();

                        continue;
                    }

                    [$role, $warnings, $newPermissions, $isDefault, $notes] = $result;
                    $taken[$role->id] = $legacyId;
                    $defaultLegacyId ??= $isDefault ? $legacyId : null;
                    foreach ($newPermissions as $permission) {
                        $known[$permission] = true;
                        $createdPermissions[$permission] = true;
                    }
                    array_map($report->note(...), $notes);
                    array_map($report->warn(...), $warnings);
                    $report->written(self::ENTITY);
                }

                $unknown = array_keys(array_diff_key($createdPermissions, array_flip(Permissions::known())));
                if ($unknown) {
                    $report->warn(count($unknown).' permission(s) unknown to the new instance were created: '.implode(', ', $unknown));
                }
                $only = Role::whereNotIn('id', array_keys($taken))->orderBy('id')->pluck('name')->all();
                if ($only) {
                    $report->warn('Roles that exist only in the new instance (kept): '.implode(', ', $only));
                }
            });
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * @param  array<int, string>  $taken
     * @param  array<string, true>  $known
     * @return array{0: Role, 1: list<string>, 2: list<string>, 3: bool, 4: list<string>} role, warnings, created permissions, became default, notes
     */
    private function importRow(ImportContext $ctx, object $row, string $base, string $name, bool $suffixed, array $taken, array $known, ?string $defaultLegacyId): array
    {
        $legacyId = (string) $row->id;
        $warnings = [];
        $notes = [];

        $role = ($mapped = $ctx->ids()->get(self::ENTITY, $legacyId)) ? Role::find($mapped) : null;
        if (! $role && ! $suffixed) {
            $role = Role::whereRaw('lower(name) = ?', [mb_strtolower($name)])->where('guard_name', 'web')->first();
            if ($role && ! isset($taken[$role->id])) {
                $notes[] = "Legacy role {$legacyId}: adopted existing role {$role->name}.";
                $name = $role->name;
            } else {
                $role = null;
            }
        }
        $role ??= new Role(['guard_name' => 'web', 'is_default' => false]);

        $priority = (int) $row->priority;
        $clamped = max(1, min(999, $priority));
        if ($clamped !== $priority) {
            $warnings[] = "Legacy role {$legacyId}: priority {$priority} is out of range, set to {$clamped}.";
        }
        $role->priority = $clamped;
        $role->color = trim((string) $row->color) ?: 'secondary';

        $isDefault = false;
        if ($row->default) {
            if ($defaultLegacyId === null) {
                $role->is_default = $isDefault = true;
            } else {
                $warnings[] = "Legacy role {$legacyId} is also marked default; the default of legacy role {$defaultLegacyId} is kept.";
            }
        }
        $this->saveNamed($role, $name);
        if (mb_strtolower($role->name) !== mb_strtolower($base)) {
            $warnings[] = "Legacy role {$legacyId} (\"{$base}\") is imported as \"{$role->name}\" because the name is taken.";
        }

        [$permissions, $problem] = $this->permissions($row->permissions);
        if ($problem) {
            $warnings[] = "Legacy role {$legacyId}: permissions column is not a JSON list of strings; imported without permissions.";
        }
        $permissions = array_values(array_unique(array_map(fn (string $p) => self::ALIASES[$p] ?? $p, array_diff($permissions, self::DROPPED))));
        $new = [];
        foreach ($permissions as $permission) {
            if (! isset($known[$permission])) {
                Permission::create(['name' => $permission, 'guard_name' => 'web']);
                $new[] = $permission;
            }
        }
        $role->syncPermissions($permissions);
        if ($row->admin && ! in_array('admin', $permissions, true)) {
            $warnings[] = "Legacy role {$legacyId} ({$name}) is flagged admin but lacks the admin permission.";
        }

        $ctx->ids()->put(self::ENTITY, $legacyId, $role->id);

        return [$role, $warnings, $new, $isDefault, $notes];
    }

    private function saveNamed(Role $role, string $desired): void
    {
        if ($role->exists && mb_strtolower($role->name) === mb_strtolower($desired)) {
            $role->save();

            return;
        }
        $n = 1;
        for ($try = 1; ; $try++) {
            do {
                $candidate = $n === 1 ? $desired : "{$desired} ({$n})";
                $n++;
            } while (Role::where('guard_name', 'web')->whereRaw('lower(name) = ?', [mb_strtolower($candidate)])
                ->when($role->exists, fn ($q) => $q->whereKeyNot($role->id))->exists());
            $role->name = $candidate;
            try {
                DB::transaction(fn () => $role->save());

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($try >= 20) {
                    throw $e;
                }
            }
        }
    }

    /** @return array{0: list<string>, 1: bool} permission names, true when the column was unusable */
    private function permissions(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [[], false];
        }
        $list = json_decode($raw, true);
        if (! is_array($list) || ! array_is_list($list) || array_filter($list, fn ($p) => ! is_string($p) || $p === '')) {
            return [[], true];
        }

        return [array_values(array_unique($list)), false];
    }
}
