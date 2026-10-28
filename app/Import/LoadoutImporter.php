<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Loadout lists: intra_fahrzeuge_beladung_categories => loadout_categories (entity `loadout_category`) and
 * intra_fahrzeuge_beladung_tiles => loadout_items (entity `loadout_item`), both mapped by legacy_id_map, reported
 * as two lines. `type` => `compartment` (0-2, others clamped), `priority` => `sort_order`, `veh_type` =>
 * `vehicle_type` (matched to vehicles.type by text as before), `amount` => `quantity` (negative => 0). An item that
 * moved to another category in the legacy system moves on the next run too. `created_at` is a TIMESTAMP (instant).
 */
final class LoadoutImporter implements Importer
{
    private const CHUNK = 1000;

    public function entity(): string
    {
        return 'loadout';
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function requiredTables(): array
    {
        return ['intra_fahrzeuge_beladung_categories', 'intra_fahrzeuge_beladung_tiles'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $warn = ['clamped' => [], 'noType' => [], 'negative' => [], 'orphans' => []];

        DB::transaction(function () use ($ctx, $report, &$warn) {
            $ctx->legacy()->tableWithInstants('intra_fahrzeuge_beladung_categories', ['created_at'])->chunkById(self::CHUNK, function ($rows) use ($ctx, $report, &$warn) {
                foreach ($rows as $row) {
                    $report->read('loadout_category');
                    $compartment = (int) $row->type;
                    if ($compartment < 0 || $compartment > 2) {
                        $warn['clamped'][] = (string) $row->id;
                        $compartment = max(0, min(2, $compartment));
                    }
                    $type = trim((string) $row->veh_type);
                    if ($type === '') {
                        $warn['noType'][] = (string) $row->id;
                    }
                    $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
                    $values = [
                        'title' => (string) $row->title,
                        'compartment' => $compartment,
                        'sort_order' => (int) $row->priority,
                        'vehicle_type' => $type === '' ? null : (string) $row->veh_type,
                        'created_at' => $created,
                        'updated_at' => $created,
                    ];
                    $this->write($ctx, 'loadout_category', (string) $row->id, 'loadout_categories', $values);
                }
            });

            $categories = $ctx->ids()->forEntity('loadout_category');
            $ctx->legacy()->tableWithInstants('intra_fahrzeuge_beladung_tiles', ['created_at'])->chunkById(self::CHUNK, function ($rows) use ($ctx, $report, $categories, &$warn) {
                foreach ($rows as $row) {
                    $report->read('loadout_item');
                    $category = $categories[(string) $row->category] ?? null;
                    if ($category === null) {
                        $warn['orphans'][] = (string) $row->id;

                        continue;
                    }
                    if ((int) $row->amount < 0) {
                        $warn['negative'][] = (string) $row->id;
                    }
                    $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
                    $values = [
                        'loadout_category_id' => $category,
                        'title' => (string) $row->title,
                        'quantity' => max(0, (int) $row->amount),
                        'sort_order' => (int) $row->sort_order,
                        'created_at' => $created,
                        'updated_at' => $created,
                    ];
                    $this->write($ctx, 'loadout_item', (string) $row->id, 'loadout_items', $values);
                }
            });
        });

        $report->warnRows('loadout categor(y/ies) with a compartment outside 0-2, clamped', $warn['clamped']);
        $report->warnRows('loadout categor(y/ies) without vehicle type: they apply to every type, as in the legacy eNOTF vehicle info (the legacy admin vehicle page showed them on none)', $warn['noType']);
        $report->warnRows('loadout item(s) with a negative amount, set to 0', $warn['negative']);
        if ($warn['orphans'] !== []) {
            $report->skippedCount('loadout_item', count($warn['orphans']));
            $report->warnRows('loadout item(s) of categories that were not imported were skipped', $warn['orphans']);
        }
    }

    /** @param array<string, mixed> $values */
    private function write(ImportContext $ctx, string $entity, string $legacyId, string $table, array $values): void
    {
        try {
            DB::transaction(fn () => $ctx->ids()->write($entity, $legacyId, $table, $values));
        } catch (Throwable $e) {
            $ctx->report()->failed($entity, "legacy {$entity} {$legacyId}: ".ImportReport::describe($e));

            return;
        }
        $ctx->report()->written($entity);
    }
}
