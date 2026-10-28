<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_edivi_pois => pois, only the core columns (the eNOTF importer adds its own data), mapped by legacy_id_map (entity
 * `poi`). The table exists only with eNOTF or since the station migration; without it the entity is skipped with
 * a note, so it is not in requiredTables().
 */
final class PoiImporter implements Importer
{
    private const ENTITY = 'poi';

    private const CHUNK = 500;

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
        if (! $ctx->legacy()->tableExists('intra_edivi_pois')) {
            $report->note(self::ENTITY.': the legacy table intra_edivi_pois does not exist, no POIs imported (vehicles get no station)');

            return;
        }

        DB::transaction(function () use ($ctx, $report) {
            $ctx->legacy()->tableWithInstants('intra_edivi_pois', ['created_at', 'updated_at'])->chunkById(self::CHUNK, function ($rows) use ($ctx, $report) {
                foreach ($rows as $row) {
                    $report->read(self::ENTITY);
                    $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
                    $values = [
                        'name' => (string) $row->name,
                        'street' => self::nullable($row->strasse),
                        'house_number' => self::nullable($row->hnr),
                        'city' => (string) $row->ort,
                        'district' => self::nullable($row->ortsteil),
                        'type' => self::nullable($row->typ ?? null),
                        'legacy_identifier' => self::nullable($row->legacy_identifier ?? null),
                        'is_active' => (bool) $row->active,
                        'created_at' => $created,
                        'updated_at' => LegacyClock::instant($row, 'updated_at') ?? $created,
                    ];
                    try {
                        DB::transaction(fn () => $ctx->ids()->write(self::ENTITY, (string) $row->id, 'pois', $values));
                    } catch (Throwable $e) {
                        $report->failed(self::ENTITY, "legacy poi {$row->id}: ".ImportReport::describe($e));

                        continue;
                    }
                    $report->written(self::ENTITY);
                }
            });
        });
    }

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
