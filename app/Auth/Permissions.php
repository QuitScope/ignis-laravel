<?php

namespace App\Auth;

use App\Modules\ModuleRegistry;
use LogicException;

/**
 * Registry of the permissions (pattern SettingDefinitions): name, picker group, owning module, label.
 * Core permissions are registered here, modules register theirs in their provider's register() with `module:`.
 * The catalog (role editor, a super admin's permission list) holds the core and the modules that are on; permissions of
 * a switched-off module stay stored on roles but are hidden (known() still lists them, for the importer).
 * Labels: group `permissions.group.<group>`, permission `permissions.<name>` (or the label passed to register()).
 */
final class Permissions
{
    private const CORE = [
        'users' => ['users.view', 'users.edit', 'users.create', 'users.delete'],
        'personnel' => ['personnel.view', 'personnel.edit', 'personnel.delete', 'personnel.comment.delete', 'personnel.documents.manage', 'personnel.documents.view', 'audit.view'],
        'files' => ['files.upload', 'files.log.view'],
        'other' => ['admin', 'dashboard.manage', 'vehicles.view', 'vehicles.manage', 'pois.view', 'pois.manage'],
    ];

    /** @var array<string, array{group: string, module: ?string, label: string}> */
    private array $definitions = [];

    public function __construct()
    {
        foreach (self::CORE as $group => $names) {
            foreach ($names as $name) {
                $this->add($name, $group, null, null);
            }
        }
    }

    public static function register(string $name, string $group, ?string $module = null, ?string $label = null): void
    {
        app(self::class)->add($name, $group, $module === null ? null : strtolower($module), $label);
    }

    /**
     * Core and active modules: name => display-name translation key.
     *
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        return array_map(fn (array $d) => $d['label'], self::active());
    }

    /**
     * Picker groups of the core and active modules, in registration order: group key => permission names.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::active() as $name => $d) {
            $groups[$d['group']][] = $name;
        }

        return $groups;
    }

    /** @return list<string> every registered name, switched-off modules included */
    public static function known(): array
    {
        return array_keys(app(self::class)->definitions);
    }

    public static function moduleOf(string $name): ?string
    {
        return app(self::class)->definitions[$name]['module'] ?? null;
    }

    public static function hidden(string $name): bool
    {
        $module = self::moduleOf($name);

        return $module !== null && ! ModuleRegistry::active($module);
    }

    /** @return array<string, array{group: string, module: ?string, label: string}> */
    private static function active(): array
    {
        return array_filter(app(self::class)->definitions, fn (array $d) => ModuleRegistry::active($d['module']));
    }

    private function add(string $name, string $group, ?string $module, ?string $label): void
    {
        $existing = $this->definitions[$name] ?? null;
        if ($existing !== null) {
            if ($existing['group'] !== $group || $existing['module'] !== $module) {
                throw new LogicException("Permission {$name} is already registered in group {$existing['group']} (module ".($existing['module'] ?? 'core').')');
            }

            return;
        }
        $this->definitions[$name] = ['group' => $group, 'module' => $module, 'label' => $label ?? 'permissions.'.$name];
    }
}
