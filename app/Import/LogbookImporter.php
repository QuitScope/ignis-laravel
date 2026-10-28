<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Modules\Logbook\Models\LogbookEntry;
use Throwable;

/**
 * intra_fahrtenbuch => logbook_entries, mapped by legacy_id_map (entity `logbook`).
 *
 * - `datum`/`abfahrt`/`ankunft` are wall clock and stay as they are; `created_at`/`updated_at` are TIMESTAMPs (instants).
 * - `fahrttyp`: the legacy keys carry a "-fahrt" suffix, the new ones do not; unknown => `sonstige` (counted).
 * - `vehicle_id` via the vehicle map; a vehicle that is gone (or not imported) leaves null, the identifier text stays (counted).
 * - `created_by` via the user map, else null (counted). Unknown `source` => `admin`; negative `kilometer` => 0 (counted).
 */
final class LogbookImporter implements Importer
{
    private const ENTITY = 'logbook';

    private const CHUNK = 500;

    public const TYPES = [
        'einsatzfahrt' => 'einsatz', 'bewegungsfahrt' => 'bewegung', 'werkstattfahrt' => 'werkstatt',
        'uebungsfahrt' => 'uebung', 'dienstfahrt' => 'dienst', 'sonstige' => 'sonstige',
    ];

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['vehicle', 'user'];
    }

    public function requiredTables(): array
    {
        return ['intra_fahrtenbuch'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $vehicles = $ctx->ids()->forEntity('vehicle');
        $users = $ctx->ids()->forEntity('user');
        /** @var array<string, list<string>> $warn */
        $warn = [];

        DB::transaction(function () use ($ctx, $report, $vehicles, $users, &$warn) {
            $ctx->legacy()->tableWithInstants('intra_fahrtenbuch', ['created_at', 'updated_at'])->chunkById(self::CHUNK, function ($rows) use ($ctx, $report, $vehicles, $users, &$warn) {
                foreach ($rows as $row) {
                    $report->read(self::ENTITY);
                    $legacyId = (string) $row->id;
                    $problems = [];
                    $vehicleId = $row->vehicle_id === null ? null : ($vehicles[(string) $row->vehicle_id] ?? null);
                    if ($vehicleId === null) {
                        $problems[] = 'vehicle gone or not imported, kept without vehicle';
                    }
                    $createdBy = $row->created_by === null ? null : ($users[(string) $row->created_by] ?? null);
                    if ($row->created_by !== null && $createdBy === null) {
                        $problems[] = 'creating account not imported, left empty';
                    }
                    $type = self::TYPES[(string) $row->fahrttyp] ?? null;
                    if ($type === null) {
                        $problems[] = "unknown trip type '".mb_substr((string) $row->fahrttyp, 0, 50)."', set to sonstige";
                        $type = 'sonstige';
                    }
                    $source = (string) $row->source;
                    if (! in_array($source, LogbookEntry::SOURCES, true)) {
                        $problems[] = "unknown source '".mb_substr($source, 0, 20)."', set to admin";
                        $source = 'admin';
                    }
                    $km = (float) $row->kilometer;
                    if ($km < 0) {
                        $problems[] = 'negative distance, set to 0';
                        $km = 0.0;
                    }
                    $station = trim((string) $row->stationierungsort);
                    $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
                    $values = [
                        'vehicle_id' => $vehicleId,
                        'vehicle_identifier' => (string) $row->vehicle_identifier,
                        'trip_date' => (string) $row->datum,
                        'departed_at' => (string) $row->abfahrt,
                        'arrived_at' => $row->ankunft,
                        'station_name' => $station === '' ? null : (string) $row->stationierungsort,
                        'distance_km' => $km,
                        'reason' => $row->grund,
                        'trip_type' => $type,
                        'driver_name' => (string) $row->fahrer_name,
                        'source' => $source,
                        'created_by' => $createdBy,
                        'created_at' => $created,
                        'updated_at' => LegacyClock::instant($row, 'updated_at') ?? $created,
                    ];
                    try {
                        DB::transaction(fn () => $ctx->ids()->write(self::ENTITY, $legacyId, 'logbook_entries', $values));
                    } catch (Throwable $e) {
                        $report->failed(self::ENTITY, "legacy logbook entry {$legacyId}: ".ImportReport::describe($e));

                        continue;
                    }
                    $report->written(self::ENTITY);
                    foreach ($problems as $what) {
                        $warn[$what][] = $legacyId;
                    }
                }
            });
        });

        foreach ($warn as $what => $legacyIds) {
            $report->warnRows("logbook entr(y/ies): {$what}", $legacyIds);
        }
    }
}
