<?php

namespace App\Import;

use App\Models\PersonnelLogEntry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_mitarbeiter_log => personnel_log_entries, upserted by personnel_log_entries.legacy_id in blocks
 * (like the audit import). Entries of records that were not imported are skipped. The legacy system
 * printed system entries (rank change, modification, ...) as HTML and notes escaped; the new pages print
 * everything escaped, so system entries become plain text and notes stay as typed.
 */
final class PersonnelLogImporter implements Importer
{
    private const ENTITY = 'personnel_log';

    private const CHUNK = 1000;

    private const KNOWN_TYPES = [
        PersonnelLogEntry::NOTE, PersonnelLogEntry::POSITIVE, PersonnelLogEntry::NEGATIVE, PersonnelLogEntry::RANK_CHANGE,
        PersonnelLogEntry::MODIFICATION, PersonnelLogEntry::CREATED, PersonnelLogEntry::DOCUMENT,
    ];

    public function entity(): string
    {
        return self::ENTITY;
    }

    public function dependsOn(): array
    {
        return ['personnel'];
    }

    public function requiredTables(): array
    {
        return ['intra_mitarbeiter_log'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $personnel = $ctx->ids()->forEntity('personnel');
        $counts = ['chunks' => 0, 'unknownType' => 0, 'badMetadata' => 0, 'noTime' => 0, 'orphans' => 0];
        /** @var array<string, true> $orphanIds legacy profilid of skipped orphan rows */
        $orphanIds = [];
        $now = now()->toDateTimeString();

        DB::transaction(function () use ($ctx, $report, $personnel, &$counts, &$orphanIds, $now) {
            $ctx->legacy()->table('intra_mitarbeiter_log')->chunkById(self::CHUNK, function ($rows) use ($report, $personnel, &$counts, &$orphanIds, $now) {
                $counts['chunks']++;
                $report->read(self::ENTITY, count($rows));
                $batch = [];
                foreach ($rows as $row) {
                    $personnelId = $personnel[(string) $row->profilid] ?? null;
                    if ($personnelId === null) {
                        $counts['orphans']++;
                        $orphanIds[(string) $row->profilid] = true;

                        continue;
                    }
                    $type = (int) $row->type;
                    if (! in_array($type, self::KNOWN_TYPES, true)) {
                        $counts['unknownType']++;
                    }
                    $content = (string) $row->content;
                    if (! in_array($type, PersonnelLogEntry::NOTE_TYPES, true)) {
                        $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                    $metadata = null;
                    if ($row->metadata !== null && trim((string) $row->metadata) !== '') {
                        $decoded = json_decode((string) $row->metadata, true);
                        if (is_array($decoded)) {
                            $metadata = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        } else {
                            $counts['badMetadata']++;
                        }
                    }
                    $at = LegacyClock::toApp($row->datetime);
                    if ($at === null) {
                        $at = $now;
                        $counts['noTime']++;
                    }
                    $batch[] = [
                        'legacy_id' => (int) $row->logid,
                        'personnel_id' => $personnelId,
                        'type' => $type,
                        'content' => $content,
                        'author' => (string) $row->paneluser,
                        'metadata' => $metadata,
                        'created_at' => $at,
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
                            $report->failed(self::ENTITY, "legacy log {$entry['legacy_id']}: ".ImportReport::describe($e));
                        }
                    }
                }
            }, 'logid');
        });

        if ($counts['orphans'] > 0) {
            $report->skippedCount(self::ENTITY, $counts['orphans']);
            $ids = array_keys($orphanIds);
            $report->warn("{$counts['orphans']} log entry(ies) of ".count($ids).' personnel record(s) that were not imported were skipped (profilid: '
                .implode(', ', array_slice($ids, 0, 20)).(count($ids) > 20 ? ', ...' : '').')');
        }

        $report->note(self::ENTITY.": {$counts['chunks']} chunks of up to ".self::CHUNK.' rows');
        if ($counts['unknownType']) {
            $report->warn("{$counts['unknownType']} log entry(ies) with an unknown type, imported with that type");
        }
        if ($counts['badMetadata']) {
            $report->warn("{$counts['badMetadata']} log entry(ies) had invalid metadata, imported without metadata");
        }
        if ($counts['noTime']) {
            $report->warn("{$counts['noTime']} log entry(ies) had a zero or invalid time, import time used");
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsert(array $rows): void
    {
        DB::table('personnel_log_entries')->upsert($rows, ['legacy_id'], ['personnel_id', 'type', 'content', 'author', 'metadata', 'created_at']);
    }
}
