<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The audit import copies `context` unchanged, so imported `Fahrzeuge` entries still carry legacy
 * ids. This step rewrites `properties.context.id` (vehicle) and `context.defect_id` through the id map as JSON
 * numbers, which the activity tab of the vehicle page compares with. The legacy values move to `legacy_id` and
 * `legacy_defect_id` and every run maps from those, so a re-run gives the same result. An entry whose vehicle
 * (defect) was not imported loses `id` (`defect_id`) and keeps only the legacy value. The legacy text fallback
 * ("... [ID: n]" without context) is not mapped; such entries do not show on the vehicle page.
 *
 * Reads only the target database (`activity_log` rows with a legacy_id); requires no legacy table.
 */
final class VehicleAuditContextImporter implements Importer
{
    private const ENTITY = 'vehicle_audit_context';

    private const CHUNK = 1000;

    private const KEYS = ['id' => ['legacy_id', 'vehicle'], 'defect_id' => ['legacy_defect_id', 'vehicle_defect']];

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['audit', 'vehicle', 'vehicle_defect'];
    }

    public function requiredTables(): array
    {
        return [];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $maps = ['vehicle' => $ctx->ids()->forEntity('vehicle'), 'vehicle_defect' => $ctx->ids()->forEntity('vehicle_defect')];
        $unmapped = ['vehicle' => [], 'vehicle_defect' => []];

        DB::transaction(function () use ($report, $maps, &$unmapped) {
            DB::table('activity_log')->where('log_name', 'Fahrzeuge')->whereNotNull('legacy_id')->whereNotNull('properties')
                ->select(['id', 'legacy_id', 'properties'])
                ->chunkById(self::CHUNK, function ($rows) use ($report, $maps, &$unmapped) {
                    foreach ($rows as $row) {
                        $properties = json_decode((string) $row->properties, true);
                        $context = is_array($properties) ? ($properties['context'] ?? null) : null;
                        if (! is_array($context) || ! array_filter(self::KEYS, fn (array $k, string $key) => isset($context[$key]) || isset($context[$k[0]]), ARRAY_FILTER_USE_BOTH)) {
                            continue;
                        }
                        $report->read(self::ENTITY);
                        $missing = false;
                        foreach (self::KEYS as $key => [$legacyKey, $entity]) {
                            $legacy = $context[$legacyKey] ?? $context[$key] ?? null;
                            if ($legacy === null) {
                                continue;
                            }
                            $context[$legacyKey] = $legacy;
                            $new = is_scalar($legacy) && ctype_digit((string) $legacy) ? ($maps[$entity][(string) $legacy] ?? null) : null;
                            if ($new === null) {
                                unset($context[$key]);
                                $unmapped[$entity][] = (string) $row->legacy_id;
                                $missing = $missing || $key === 'id';
                            } else {
                                $context[$key] = $new;
                            }
                        }
                        $properties['context'] = $context;
                        try {
                            DB::table('activity_log')->where('id', $row->id)
                                ->update(['properties' => json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                        } catch (Throwable $e) {
                            $report->failed(self::ENTITY, "audit entry {$row->legacy_id}: ".ImportReport::describe($e));

                            continue;
                        }
                        $missing ? $report->skippedCount(self::ENTITY, 1) : $report->written(self::ENTITY);
                    }
                });
        });

        $report->warnRows('Fahrzeuge audit entry(ies) refer to a vehicle that was not imported; they keep only context.legacy_id and do not show on a vehicle page', $unmapped['vehicle']);
        $report->warnRows('Fahrzeuge audit entry(ies) refer to a defect that was not imported; they keep only context.legacy_defect_id', $unmapped['vehicle_defect']);
    }
}
