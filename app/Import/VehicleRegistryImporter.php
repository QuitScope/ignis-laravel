<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_fahrzeuge_import_queue => vehicle_registry_entries, only decided entries (`accepted`, `rejected`) as history,
 * mapped by legacy_id_map (entity `vehicle_registry`). `pending` entries are discarded (counted): the next register
 * from the FiveM resource replaces them anyway. `raw_data` => `payload` when it is a JSON object or list, else null.
 * `processed_by` via the user map, else null. `created_at` is a TIMESTAMP (instant), `processed_at` was written with NOW() (LegacyDbClock).
 */
final class VehicleRegistryImporter implements Importer
{
    private const ENTITY = 'vehicle_registry';

    private const CHUNK = 1000;

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['user'];
    }

    public function requiredTables(): array
    {
        return ['intra_fahrzeuge_import_queue'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $dbClock = LegacyDbClock::for($ctx->legacy(), $report);
        $users = $ctx->ids()->forEntity('user');
        $warn = ['pending' => 0, 'badPayload' => [], 'service' => [], 'processor' => []];

        DB::transaction(function () use ($ctx, $report, $dbClock, $users, &$warn) {
            $ctx->legacy()->tableWithInstants('intra_fahrzeuge_import_queue', ['created_at'])->chunkById(self::CHUNK, function ($rows) use ($ctx, $report, $dbClock, $users, &$warn) {
                foreach ($rows as $row) {
                    $report->read(self::ENTITY);
                    $legacyId = (string) $row->id;
                    $status = (string) $row->status;
                    if (! in_array($status, ['accepted', 'rejected'], true)) {
                        $warn['pending']++;

                        continue;
                    }
                    $payload = null;
                    if ($row->raw_data !== null && trim((string) $row->raw_data) !== '') {
                        $decoded = json_decode((string) $row->raw_data);
                        if (is_array($decoded) || is_object($decoded)) {
                            $payload = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        } else {
                            $warn['badPayload'][] = $legacyId;
                        }
                    }
                    $service = (int) $row->rd_type;
                    if ($service < 0 || $service > 3) {
                        $warn['service'][] = $legacyId;
                        $service = 0;
                    }
                    $processor = null;
                    if ($row->processed_by !== null) {
                        $processor = $users[(string) $row->processed_by] ?? null;
                        if ($processor === null) {
                            $warn['processor'][] = $legacyId;
                        }
                    }
                    $values = [
                        'emd_vehicle_id' => $row->emd_vehicle_id !== null && (int) $row->emd_vehicle_id >= 0 ? (int) $row->emd_vehicle_id : null,
                        'name' => (string) $row->name,
                        'identifier' => $row->identifier,
                        'type' => $row->veh_type,
                        'service' => $service,
                        'department' => $row->department,
                        'value_long' => $row->valuelong,
                        'job' => $row->job,
                        'image' => $row->image,
                        'radio_channel' => $row->funkkanal,
                        'payload' => $payload,
                        'status' => $status,
                        'processed_by' => $processor,
                        'processed_at' => $dbClock->toApp($row->processed_at),
                        'created_at' => LegacyClock::instant($row, 'created_at'),
                    ];
                    try {
                        DB::transaction(fn () => $ctx->ids()->write(self::ENTITY, $legacyId, 'vehicle_registry_entries', $values));
                    } catch (Throwable $e) {
                        $report->failed(self::ENTITY, "legacy registry entry {$legacyId}: ".ImportReport::describe($e));

                        continue;
                    }
                    $report->written(self::ENTITY);
                }
            });
        });

        if ($warn['pending'] > 0) {
            $report->skippedCount(self::ENTITY, $warn['pending']);
            $report->note(self::ENTITY.": {$warn['pending']} pending entr(y/ies) discarded (the next register from the FiveM resource replaces them)");
        }
        $report->warnRows('registry entr(y/ies) with invalid raw_data JSON, imported without payload', $warn['badPayload']);
        $report->warnRows('registry entr(y/ies) with rd_type outside 0-3, service set to 0', $warn['service']);
        $report->warnRows('registry entr(y/ies) processed by an account that was not imported, imported without processor', $warn['processor']);
    }
}
