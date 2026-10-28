<?php

namespace App\Import;

use App\Modules\ModuleRegistry;
use Throwable;

/**
 * intra_plugins.enabled => modules.<name>.enabled through LegacyConfigMap::PLUGINS. A switch of a module that is not
 * installed yet is written anyway (it takes effect once the module arrives, docs/dev/modules.md); unknown plugin ids
 * and core modules (never switchable) are skipped.
 */
final class ModuleSwitchImporter implements Importer
{
    private const ENTITY = 'module_switch';

    public function __construct(private readonly ModuleRegistry $modules) {}

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
        return [];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        if (! $ctx->legacy()->tableExists('intra_plugins')) {
            $report->note(self::ENTITY.': legacy table intra_plugins missing, skipped.');

            return;
        }

        $installed = $this->modules->all();
        $unknown = $kept = $core = [];
        $rows = $ctx->legacy()->table('intra_plugins')->orderBy('plugin_id')->get(['plugin_id', 'enabled']);
        $report->read(self::ENTITY, $rows->count());
        foreach ($rows as $row) {
            $id = (string) $row->plugin_id;
            $name = LegacyConfigMap::PLUGINS[$id] ?? null;
            if ($name === null || ($installed[$name]->core ?? false)) {
                if ($name === null) {
                    $unknown[] = $id;
                } else {
                    $core[] = $id;
                }
                $report->skippedCount(self::ENTITY, 1);

                continue;
            }
            try {
                $outcome = $ctx->fillSetting("modules.{$name}.enabled", (bool) $row->enabled);
            } catch (Throwable $e) {
                $report->failed(self::ENTITY, "{$id}: ".ImportReport::describe($e));

                continue;
            }
            if ($outcome === 'kept') {
                $kept[] = $name;
                $report->skippedCount(self::ENTITY, 1);
            } else {
                $report->written(self::ENTITY);
            }
        }

        $report->warnRows('unbekannte Plugins nicht übernommen', $unknown);
        if ($core) {
            $report->note(self::ENTITY.': Kernmodule sind nicht abschaltbar, Schalter nicht übernommen: '.ImportReport::idList($core));
        }
        if ($kept) {
            $report->note(self::ENTITY.': Neusystem-Wert bleibt; Altwert unterscheidet sich — mit --overwrite-settings übernehmen: '.ImportReport::idList($kept));
        }
    }
}
