<?php

namespace App\Import;

use App\Models\User;
use App\Notifications\NotificationKinds;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * intra_notifications => notifications (Laravel's table), upserted by id in blocks. The id is a UUIDv5 of the legacy
 * id under a fixed namespace (uuid()), so every run writes the same ids (the id map only holds integers).
 *
 * - `user_id` without an imported account: skipped (counted). `type` stays raw; kinds that are not registered (yet)
 *   are counted and imported anyway (the inbox shows them with the raw key until their module registers them).
 * - `data` = {title, message, link}. The link loses the legacy BASE_PATH (config ignis.import.legacy_base_path), known
 *   legacy pages are rewritten (LegacyLinkRewriter), other internal paths stay, everything else is dropped (counted).
 *   The message is cut only as far as needed for the JSON to fit the TEXT column (data()).
 * - Clocks: `created_at` is a DATETIME filled by CURRENT_TIMESTAMP and `read_at` was set with NOW() (LegacyDbClock),
 *   except the mail module, which wrote `read_at` with PHP's date() (LegacyClock). Read without a read time:
 *   `read_at` = `created_at`; unread: `read_at` stays empty. `updated_at` = the later of both.
 */
final class NotificationImporter implements Importer
{
    /** Fixed namespace of the notification ids; never change it, or a re-run duplicates every notification. */
    public const UUID_NAMESPACE = '00747f74-9f52-4135-b143-e67a702d6e73';

    private const ENTITY = 'notification';

    private const CHUNK = 1000;

    private const DATA_MAX = 65535;

    public function __construct(private readonly NotificationKinds $kinds) {}

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
        return ['intra_notifications'];
    }

    public static function uuid(int|string $legacyId): string
    {
        return Uuid::uuid5(self::UUID_NAMESPACE, 'intra_notifications:'.$legacyId)->toString();
    }

    /**
     * The `data` JSON {title, message, link} and whether the message was cut: the message is shortened (never inside a
     * character) until the encoded JSON fits a TEXT column; escapes can grow a byte up to six (`\u0001`).
     *
     * @return array{0: string, 1: bool}
     */
    public static function data(string $title, ?string $message, ?string $link): array
    {
        $title = mb_substr($title, 0, 255);
        $encode = fn (?string $m) => (string) json_encode(['title' => $title, 'message' => $m, 'link' => $link],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $json = $encode($message);
        if (strlen($json) <= self::DATA_MAX || $message === null) {
            return [$json, false];
        }
        [$low, $high] = [0, strlen($message)];
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if (strlen($encode(mb_strcut($message, 0, $mid))) <= self::DATA_MAX) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return [$encode(mb_strcut($message, 0, $low)), true];
    }

    public function import(ImportContext $ctx): void
    {
        $report = $ctx->report();
        $dbClock = LegacyDbClock::for($ctx->legacy(), $report);
        $report->note(self::ENTITY.': '.$dbClock->describe());
        $users = $ctx->ids()->forEntity('user');
        $links = LegacyLinkRewriter::fromIds($ctx->ids());
        $morph = (new User)->getMorphClass();
        $now = now()->toDateTimeString();
        /** @var array<string, list<string>> $warn summary warning => legacy ids */
        $warn = [];
        $state = ['chunks' => 0, 'orphans' => [], 'links' => ['rewritten' => 0, 'kept' => [], 'unmapped' => [], 'dropped' => []], 'kinds' => []];

        DB::transaction(function () use ($ctx, $report, $dbClock, $users, $links, $morph, $now, &$warn, &$state) {
            $ctx->legacy()->table('intra_notifications')->chunkById(self::CHUNK, function ($rows) use ($report, $dbClock, $users, $links, $morph, $now, &$warn, &$state) {
                $state['chunks']++;
                $report->read(self::ENTITY, count($rows));
                $batch = [];
                foreach ($rows as $row) {
                    $legacyId = (string) $row->id;
                    $userId = $users[(string) $row->user_id] ?? null;
                    if ($userId === null) {
                        $state['orphans'][] = $legacyId;

                        continue;
                    }
                    $type = (string) $row->type;
                    if (! $this->kinds->has($type)) {
                        $state['kinds'][$type][] = $legacyId;
                    }
                    [$link, $outcome] = $links->notificationLink($row->link);
                    if ($outcome === 'rewritten') {
                        $state['links']['rewritten']++;
                    } elseif ($outcome !== 'none') {
                        $state['links'][$outcome][] = $legacyId;
                    }
                    $message = $row->message === null || $row->message === '' ? null : (string) $row->message;
                    [$data, $cut] = self::data((string) $row->title, $message, $link);
                    if ($cut) {
                        $warn['with a message too long for the data column ('.self::DATA_MAX.' bytes as JSON), cut'][] = $legacyId;
                    }
                    $created = $dbClock->toApp($row->created_at);
                    if ($created === null) {
                        $warn['had a zero or invalid creation time, import time used'][] = $legacyId;
                        $created = $now;
                    }
                    $readAt = null;
                    if ((int) $row->is_read === 1) {
                        $readAt = $type === 'mail' ? LegacyClock::toApp($row->read_at) : $dbClock->toApp($row->read_at);
                        if ($readAt === null) {
                            $warn['marked read without a read time, read_at set to created_at'][] = $legacyId;
                            $readAt = $created;
                        }
                    }
                    $batch[] = [
                        'id' => self::uuid($legacyId),
                        'type' => $type,
                        'notifiable_type' => $morph,
                        'notifiable_id' => $userId,
                        'data' => $data,
                        'read_at' => $readAt,
                        'created_at' => $created,
                        'updated_at' => max($created, $readAt ?? $created),
                        'legacy_id' => $legacyId,
                    ];
                }
                $this->write($report, $batch);
            });
        });

        $report->note(self::ENTITY.": {$state['chunks']} chunks of up to ".self::CHUNK.' rows');
        $links = $state['links'];
        $report->note(self::ENTITY.": links {$links['rewritten']} rewritten, ".count($links['kept']).' kept unchanged, '
            .count($links['unmapped']).' rewritten to a list page, '.count($links['dropped']).' dropped');
        if ($state['orphans'] !== []) {
            $report->skippedCount(self::ENTITY, count($state['orphans']));
            $report->warnRows('notification(s) of accounts that were not imported were skipped', $state['orphans']);
        }
        ksort($state['kinds']);
        foreach ($state['kinds'] as $type => $legacyIds) {
            $report->warnRows("notification(s) of the unregistered kind '".mb_substr((string) $type, 0, 50)."', imported with the raw key "
                .'(every recipient sees them until the module registers the kind)', $legacyIds);
        }
        foreach ($warn as $what => $legacyIds) {
            $report->warnRows("notification(s) {$what}", $legacyIds);
        }
        $report->warnRows('notification link(s) were not an internal path and were dropped, imported without link', $links['dropped']);
        $report->warnRows('notification link(s) point at a record that was not imported (vehicle, personnel file, application), rewritten to the list page', $links['unmapped']);
        $report->warnRows('notification link(s) kept unchanged: pages LegacyLinkRewriter does not know, they may still hold LEGACY record ids', $links['kept']);
    }

    /** @param list<array<string, mixed>> $batch */
    private function write(ImportReport $report, array $batch): void
    {
        if ($batch === []) {
            return;
        }
        $rows = array_map(fn (array $r) => array_diff_key($r, ['legacy_id' => true]), $batch);
        try {
            DB::transaction(fn () => $this->upsert($rows));
            $report->written(self::ENTITY, count($rows));
        } catch (Throwable) {
            foreach ($rows as $i => $row) {
                try {
                    DB::transaction(fn () => $this->upsert([$row]));
                    $report->written(self::ENTITY);
                } catch (Throwable $e) {
                    $report->failed(self::ENTITY, "legacy notification {$batch[$i]['legacy_id']}: ".ImportReport::describe($e));
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsert(array $rows): void
    {
        DB::table('notifications')->upsert($rows, ['id'], ['type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at', 'updated_at']);
    }
}
