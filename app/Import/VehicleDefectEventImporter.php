<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_fahrzeuge_defect_log => vehicle_defect_events, upserted by vehicle_defect_events.legacy_id in blocks (like
 * the personnel log). `user_id` 0 (crew) or an account that was not imported => null; `action` keeps the legacy
 * text (also vehicle_disabled/vehicle_enabled, which the new defect flow no longer writes). `created_at` is a
 * TIMESTAMP (instant).
 */
final class VehicleDefectEventImporter implements Importer
{
    private const ENTITY = 'vehicle_defect_event';

    private const CHUNK = 1000;

    private const ACTION_MAX = 30;

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['vehicle_defect', 'user'];
    }

    public function requiredTables(): array
    {
        return ['intra_fahrzeuge_defect_log'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $defects = $ctx->ids()->forEntity('vehicle_defect');
        $users = $ctx->ids()->forEntity('user');
        $state = ['chunks' => 0, 'orphans' => [], 'unmappedUser' => [], 'longAction' => [], 'noTime' => []];
        $now = now()->toDateTimeString();

        DB::transaction(function () use ($ctx, $report, $defects, $users, &$state, $now) {
            $ctx->legacy()->tableWithInstants('intra_fahrzeuge_defect_log', ['created_at'])->chunkById(self::CHUNK, function ($rows) use ($report, $defects, $users, &$state, $now) {
                $state['chunks']++;
                $report->read(self::ENTITY, count($rows));
                $batch = [];
                foreach ($rows as $row) {
                    $defectId = $defects[(string) $row->defect_id] ?? null;
                    if ($defectId === null) {
                        $state['orphans'][] = (string) $row->id;

                        continue;
                    }
                    $userId = $users[(string) $row->user_id] ?? null;
                    if ($userId === null && (int) $row->user_id !== 0) {
                        $state['unmappedUser'][] = (string) $row->id;
                    }
                    $action = (string) $row->action;
                    if (mb_strlen($action) > self::ACTION_MAX) {
                        $state['longAction'][] = (string) $row->id;
                        $action = mb_substr($action, 0, self::ACTION_MAX);
                    }
                    $at = LegacyClock::instant($row, 'created_at');
                    if ($at === null) {
                        $state['noTime'][] = (string) $row->id;
                    }
                    $batch[] = [
                        'legacy_id' => (int) $row->id,
                        'vehicle_defect_id' => $defectId,
                        'user_id' => $userId,
                        'action' => $action,
                        'details' => $row->details,
                        'created_at' => $at ?? $now,
                    ];
                }
                if ($batch === []) {
                    return;
                }
                try {
                    DB::transaction(fn () => $this->upsert($batch));
                    $report->written(self::ENTITY, count($batch));
                } catch (Throwable) {
                    foreach ($batch as $entry) {
                        try {
                            DB::transaction(fn () => $this->upsert([$entry]));
                            $report->written(self::ENTITY);
                        } catch (Throwable $e) {
                            $report->failed(self::ENTITY, "legacy defect log {$entry['legacy_id']}: ".ImportReport::describe($e));
                        }
                    }
                }
            });
        });

        $report->note(self::ENTITY.": {$state['chunks']} chunks of up to ".self::CHUNK.' rows');
        if ($state['orphans'] !== []) {
            $report->skippedCount(self::ENTITY, count($state['orphans']));
            $report->warnRows('defect history entry(ies) of defects that were not imported were skipped', $state['orphans']);
        }
        $report->warnRows('defect history entry(ies) by an account that was not imported, imported without user', $state['unmappedUser']);
        $report->warnRows('defect history entry(ies) with an action over '.self::ACTION_MAX.' characters, cut', $state['longAction']);
        $report->warnRows('defect history entry(ies) had a zero or invalid time, import time used', $state['noTime']);
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsert(array $rows): void
    {
        DB::table('vehicle_defect_events')->upsert($rows, ['legacy_id'], ['vehicle_defect_id', 'user_id', 'action', 'details', 'created_at']);
    }
}
