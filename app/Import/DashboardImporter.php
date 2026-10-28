<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * intra_dashboard_categories / intra_dashboard_tiles => dashboard_categories / dashboard_links, mapped by
 * legacy_id_map (entities `dashboard_category`, `dashboard_link`; report rows of the same names).
 *
 * - `priority` => `sort_order`, clamped to 0-9999 like the form; titles are trimmed, over 255 characters cut, empty
 *   ones skipped (the form requires a title).
 * - `url` goes through LegacyLinkRewriter::tileTarget() like a notification link: legacy BASE_PATH stripped, known
 *   legacy pages rewritten with their mapped ids, a relative path (the legacy page rendered it below BASE_PATH) gets
 *   a leading `/`, then it must pass LinkTarget like a link saved in the settings. A refused target (`javascript:`,
 *   over 255 characters, …) and the placeholders `#`, `#…`, `?…` are never stored: the link is skipped and the
 *   report names it with its raw target.
 * - `icon`: trimmed and lower-cased (the legacy check ignored case); only letters, digits, blanks and dashes up to
 *   100 characters stay, as the form allows, otherwise no icon. The Font Awesome classes stay as they are
 *   (resources/js/lib/tileIcons.ts maps them).
 * - `created_at` is a TIMESTAMP (instant); `updated_at` = `created_at`.
 * Both tables exist since the first legacy release; without them the entity is skipped with a note.
 */
final class DashboardImporter implements Importer
{
    private const CHUNK = 500;

    private const SORT_MAX = 9999;

    private const ICON_MAX = 100;

    public function entity(): string
    {
        return 'dashboard';
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
        foreach (['intra_dashboard_categories', 'intra_dashboard_tiles'] as $table) {
            if (! $ctx->legacy()->tableExists($table)) {
                $report->note("dashboard: the legacy table {$table} does not exist, no quick links imported");

                return;
            }
        }
        /** @var array<string, list<string>> $warn summary warning => legacy ids */
        $warn = [];
        $skippedCategories = 0;

        DB::transaction(function () use ($ctx, $report, &$warn, &$skippedCategories) {
            $ctx->legacy()->tableWithInstants('intra_dashboard_categories', ['created_at'])->chunkById(self::CHUNK, function ($rows) use ($ctx, $report, &$warn, &$skippedCategories) {
                foreach ($rows as $row) {
                    $report->read('dashboard_category');
                    $legacyId = (string) $row->id;
                    $title = self::title($row->title, 'quick link categor(y/ies)', $legacyId, $warn);
                    if ($title === null) {
                        $skippedCategories++;

                        continue;
                    }
                    $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
                    $values = [
                        'title' => $title,
                        'sort_order' => self::sortOrder($row->priority, 'quick link categor(y/ies)', $legacyId, $warn),
                        'created_at' => $created,
                        'updated_at' => $created,
                    ];
                    try {
                        DB::transaction(fn () => $ctx->ids()->write('dashboard_category', $legacyId, 'dashboard_categories', $values));
                        $report->written('dashboard_category');
                    } catch (Throwable $e) {
                        $report->failed('dashboard_category', "legacy quick link category {$legacyId}: ".ImportReport::describe($e));
                    }
                }
            });
        });
        $report->skippedCount('dashboard_category', $skippedCategories);

        $categories = $ctx->ids()->forEntity('dashboard_category');
        $links = LegacyLinkRewriter::fromIds($ctx->ids());
        $state = ['orphans' => [], 'refused' => [], 'placeholder' => [], 'skipped' => 0];
        DB::transaction(function () use ($ctx, $report, $categories, $links, &$warn, &$state) {
            $ctx->legacy()->tableWithInstants('intra_dashboard_tiles', ['created_at'])->chunkById(self::CHUNK, function ($rows) use ($ctx, $report, $categories, $links, &$warn, &$state) {
                foreach ($rows as $row) {
                    $report->read('dashboard_link');
                    $legacyId = (string) $row->id;
                    $category = $categories[(string) $row->category] ?? null;
                    if ($category === null) {
                        $state['orphans'][] = $legacyId;

                        continue;
                    }
                    $title = self::title($row->title, 'quick link(s)', $legacyId, $warn);
                    if ($title === null) {
                        $state['skipped']++;

                        continue;
                    }
                    $raw = (string) $row->url;
                    [$url, $outcome, $prefixed] = $links->tileTarget($raw);
                    if ($url === null) {
                        $state[$outcome === 'placeholder' ? 'placeholder' : 'refused'][] = $legacyId." '".mb_substr($raw, 0, 60).(mb_strlen($raw) > 60 ? '...' : '')."'";

                        continue;
                    }
                    if ($prefixed) {
                        $warn['quick link(s) with a relative target (no leading /) were prefixed with /'][] = $legacyId;
                    }
                    if ($outcome === 'rewritten' && $url !== LegacyLinkRewriter::withoutBasePath(trim($raw))) {
                        $warn['quick link(s) with a legacy page target rewritten to the new page'][] = $legacyId;
                    } elseif ($outcome === 'unmapped') {
                        $warn['quick link(s) point at a record that was not imported (vehicle, personnel file, application), rewritten to the list page'][] = $legacyId;
                    } elseif ($outcome === 'kept' && preg_match('~\?|/\d~', $url) === 1) {
                        $warn['quick link(s) keep a target with a query or id of a module page that is not ported yet; it still holds LEGACY record ids'][] = $legacyId;
                    }
                    $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
                    $values = [
                        'dashboard_category_id' => $category,
                        'title' => $title,
                        'url' => $url,
                        'icon' => self::icon($row->icon, $legacyId, $warn),
                        'sort_order' => self::sortOrder($row->priority, 'quick link(s)', $legacyId, $warn),
                        'created_at' => $created,
                        'updated_at' => $created,
                    ];
                    try {
                        DB::transaction(fn () => $ctx->ids()->write('dashboard_link', $legacyId, 'dashboard_links', $values));
                        $report->written('dashboard_link');
                    } catch (Throwable $e) {
                        $report->failed('dashboard_link', "legacy quick link {$legacyId}: ".ImportReport::describe($e));
                    }
                }
            });
        });

        $report->skippedCount('dashboard_link', count($state['orphans']) + count($state['refused']) + count($state['placeholder']) + $state['skipped']);
        foreach ($warn as $what => $legacyIds) {
            $report->warnRows($what, $legacyIds);
        }
        $report->warnRows('quick link(s) of categories that were not imported were skipped', $state['orphans']);
        if ($state['placeholder'] !== []) {
            $report->warn(count($state['placeholder']).' quick link(s) with a placeholder or anchor-only target (#, #…, ?…) were skipped: they pointed nowhere '
                .'(legacy id and target: '.ImportReport::idList($state['placeholder']).')');
        }
        if ($state['refused'] !== []) {
            $report->warn(count($state['refused']).' quick link(s) with a target the new instance refuses (only /path, http(s)://, mailto:) were skipped '
                .'(legacy id and target: '.ImportReport::idList($state['refused']).')');
        }
    }

    /**
     * The trimmed title, cut to 255 characters; null (skip) when empty.
     *
     * @param  array<string, list<string>>  $warn
     */
    private static function title(mixed $raw, string $what, string $legacyId, array &$warn): ?string
    {
        $title = trim((string) $raw);
        if ($title === '') {
            $warn["{$what} with an empty title were skipped"][] = $legacyId;

            return null;
        }
        if (mb_strlen($title) > 255) {
            $warn["{$what} with a title over 255 characters, cut"][] = $legacyId;
            $title = mb_substr($title, 0, 255);
        }

        return $title;
    }

    /** @param array<string, list<string>> $warn */
    private static function sortOrder(mixed $raw, string $what, string $legacyId, array &$warn): int
    {
        $sort = (int) $raw;
        if ($sort < 0 || $sort > self::SORT_MAX) {
            $warn["{$what} with a priority outside 0-".self::SORT_MAX.', clamped'][] = $legacyId;
        }

        return max(0, min(self::SORT_MAX, $sort));
    }

    /** @param array<string, list<string>> $warn */
    private static function icon(mixed $raw, string $legacyId, array &$warn): ?string
    {
        $icon = strtolower(trim((string) $raw));
        if ($icon === '') {
            return null;
        }
        if (strlen($icon) > self::ICON_MAX || preg_match('/^[a-z0-9 -]+$/D', $icon) !== 1) {
            $warn['quick link(s) with an icon other than lower-case letters, digits, blanks and dashes (or over '.self::ICON_MAX.' characters), imported without icon'][] = $legacyId;

            return null;
        }

        return $icon;
    }
}
