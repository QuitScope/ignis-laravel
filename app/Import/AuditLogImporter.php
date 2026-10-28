<?php

namespace App\Import;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_audit_log => activity_log. Rows are written directly (not through Audit::log), keyed by
 * activity_log.legacy_id with an upsert, so re-runs update instead of duplicating and the
 * legacy timestamps survive. Does not use legacy_id_map: it would double the row count.
 */
final class AuditLogImporter implements Importer
{
    private const ENTITY = 'audit';

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
        return ['intra_audit_log'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $users = $ctx->ids()->forEntity('user');
        $unmapped = [];
        $badContext = 0;
        $noTimestamp = 0;
        $chunks = 0;
        $now = now()->toDateTimeString();

        DB::transaction(function () use ($ctx, $report, $users, &$unmapped, &$badContext, &$noTimestamp, &$chunks, $now) {
            $ctx->legacy()->tableWithInstants('intra_audit_log', ['timestamp'])->chunkById(self::CHUNK, function ($rows) use ($report, $users, &$unmapped, &$badContext, &$noTimestamp, &$chunks, $now) {
                $chunks++;
                $report->read(self::ENTITY, count($rows));
                $batch = [];
                $chunkUnmapped = [];
                $chunkBadContext = 0;
                $chunkNoTimestamp = 0;
                foreach ($rows as $row) {
                    if ((int) $row->id <= 0) {
                        $report->skipped(self::ENTITY, 'row without a usable id');

                        continue;
                    }
                    $properties = [];
                    if ($row->context !== null && $row->context !== '') {
                        $context = json_decode((string) $row->context, true);
                        if ($context === null) {
                            $chunkBadContext++;
                        } else {
                            $properties['context'] = $context;
                        }
                    }
                    $causer = $users[(string) $row->user] ?? null;
                    if ($causer === null) {
                        $properties['legacy_user_id'] = (int) $row->user;
                        $chunkUnmapped[(int) $row->user] = true;
                    }
                    $at = LegacyClock::instant($row, 'timestamp');
                    if ($at === null) {
                        $at = $now;
                        $chunkNoTimestamp++;
                    }
                    $batch[] = [
                        'legacy_id' => (int) $row->id,
                        'log_name' => ($row->module !== null && $row->module !== '') ? $row->module : 'System',
                        'description' => (string) $row->action,
                        'details' => $row->details,
                        'is_global' => (bool) $row->global,
                        'event' => null,
                        'causer_type' => $causer === null ? null : User::class,
                        'causer_id' => $causer,
                        'properties' => $properties === [] ? null : json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at' => $at,
                        'updated_at' => $at,
                    ];
                }
                if ($batch !== []) {
                    try {
                        DB::table('activity_log')->upsert($batch, ['legacy_id'], ['log_name', 'description', 'details', 'is_global', 'event', 'causer_type', 'causer_id', 'properties', 'created_at', 'updated_at']);
                        $report->written(self::ENTITY, count($batch));
                        $unmapped += $chunkUnmapped;
                        $badContext += $chunkBadContext;
                        $noTimestamp += $chunkNoTimestamp;
                    } catch (Throwable $e) {
                        $report->failed(self::ENTITY, 'chunk '.$batch[0]['legacy_id'].'..'.$batch[array_key_last($batch)]['legacy_id'].': '.ImportReport::describe($e));
                    }
                }
            });
        });

        $report->note(self::ENTITY.": {$chunks} chunks of up to ".self::CHUNK.' rows');
        if ($unmapped) {
            $report->warn(count($unmapped).' legacy audit user id(s) unmapped (no matching imported user); entries keep properties.legacy_user_id: '.implode(', ', array_slice(array_keys($unmapped), 0, 20)).(count($unmapped) > 20 ? ', ...' : ''));
        }
        if ($badContext) {
            $report->warn("{$badContext} audit row(s) had an invalid context JSON, imported without context");
        }
        if ($noTimestamp) {
            $report->warn("{$noTimestamp} audit row(s) had a zero or invalid timestamp, import time used");
        }
    }
}
