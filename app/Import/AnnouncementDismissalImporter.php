<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_global_announcements_dismissed => announcement_dismissals, upserted by (announcement_id, user_id), so a re-run
 * changes nothing. Rows of accounts that were not imported and ids the dismiss endpoint would refuse are skipped
 * (counted). `dismissed_at` is a DATETIME the legacy app filled with NOW() (LegacyDbClock).
 * The announcement caches (hub, website changelog, blog) are not imported: the opt-in feeds refill the cache.
 */
final class AnnouncementDismissalImporter implements Importer
{
    private const ENTITY = 'announcement_dismissal';

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
        return ['intra_global_announcements_dismissed'];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $dbClock = LegacyDbClock::for($ctx->legacy(), $report);
        $users = $ctx->ids()->forEntity('user');
        $state = ['orphans' => [], 'badId' => [], 'noTime' => []];
        $now = now()->toDateTimeString();

        DB::transaction(function () use ($ctx, $report, $dbClock, $users, &$state, $now) {
            $ctx->legacy()->table('intra_global_announcements_dismissed')->chunkById(self::CHUNK, function ($rows) use ($report, $dbClock, $users, &$state, $now) {
                foreach ($rows as $row) {
                    $report->read(self::ENTITY);
                    $legacyId = (string) $row->id;
                    $userId = $users[(string) $row->user_id] ?? null;
                    if ($userId === null) {
                        $state['orphans'][] = $legacyId;

                        continue;
                    }
                    $announcement = (string) $row->announcement_id;
                    if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $announcement) !== 1) {
                        $state['badId'][] = $legacyId;

                        continue;
                    }
                    $at = $dbClock->toApp($row->dismissed_at);
                    if ($at === null) {
                        $state['noTime'][] = $legacyId;
                    }
                    $values = ['announcement_id' => $announcement, 'user_id' => $userId, 'dismissed_at' => $at ?? $now];
                    try {
                        DB::transaction(fn () => DB::table('announcement_dismissals')->upsert([$values], ['announcement_id', 'user_id'], ['dismissed_at']));
                        $report->written(self::ENTITY);
                    } catch (Throwable $e) {
                        $report->failed(self::ENTITY, "legacy announcement dismissal {$legacyId}: ".ImportReport::describe($e));
                    }
                }
            });
        });

        $report->skippedCount(self::ENTITY, count($state['orphans']) + count($state['badId']));
        $report->warnRows('announcement dismissal(s) of accounts that were not imported were skipped', $state['orphans']);
        $report->warnRows('announcement dismissal(s) with an id outside [A-Za-z0-9._:-]{1,64} were skipped', $state['badId']);
        $report->warnRows('announcement dismissal(s) had a zero or invalid time, import time used', $state['noTime']);
        $report->note('not imported: intra_global_announcements_cache, intra_changelog_cache, intra_changelog_meta, intra_blog_cache, intra_blog_meta '
            .'(caches; the opt-in announcement feeds refill theirs, the blog tables had no reader)');
    }
}
