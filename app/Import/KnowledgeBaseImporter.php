<?php

namespace App\Import;

use App\Support\ImageUpload;
use App\Support\UploadRejected;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\KnowledgeBase\Content\KbContentValidator;
use Modules\KnowledgeBase\Http\Controllers\KbImageController;
use Modules\KnowledgeBase\Import\LegacyHtmlToTiptap;
use Modules\KnowledgeBase\Models\KbEntry;
use Modules\KnowledgeBase\Models\KbEntryRelation;
use Modules\KnowledgeBase\Models\KbTag;
use Throwable;

/**
 * intra_kb_* => kb_* (module KnowledgeBase), entities kb_category, kb_tag, kb_entry in legacy_id_map. Report rows
 * kb_category, kb_tag, kb_entry, kb_entry_tag, kb_relation. Steps:
 *
 * 1. Categories, then their parents in a second pass: a parent that is gone => none; a loop (also a category that is its
 *    own parent) is broken at its lowest legacy id. Font Awesome icon classes => the bare name if it is a valid Lucide
 *    style name. sort_order clamped to 0..65535.
 * 2. Tags: names that are equal ignoring case (also an existing tag of the new instance) become one tag; colour
 *    `#rrggbb` (`#rgb` expanded, else KbTag::DEFAULT_COLOR).
 * 3. Entries in two passes: first every row (scalar columns, so all new ids exist), then the HTML of `content` and the
 *    twelve med_* / mass_* columns is converted (LegacyHtmlToTiptap) with the complete id map, so links to entries
 *    imported later resolve. Non-empty sections are kept whatever the entry's type (KbEntry::allSectionKeys()).
 *    search_text is built by KbEntry::buildSearchText(), like the entry form.
 * 4. Tag links and relations (pair stored once via KbEntryRelation::normalize; self, duplicate and dangling ones dropped).
 *
 * Links: `/lexicon/view?id=N` (also /lexicon/edit, /wissensdb/..., `.php`, relative to the legacy page, below the legacy
 * BASE_PATH, or absolute on the legacy SYSTEM_URL host) => `/lexicon/<new id>` through LegacyLinkRewriter; an entry that
 * was not imported loses the link (text stays). Images: `.../storage/kb-images/<32hex>.<ext>` => the file is copied from
 * `--uploads` (storage/kb-images) to the disk kb-images under the same name, with the upload checks (ImageUpload::check:
 * type by content, 5 MB, pixel cap, no SVG; the resolved path must stay in the folder). The extension follows the
 * detected type. A file that is missing or refused removes the image. Nothing is copied on a dry run.
 * Re-runnable: rows by legacy_id_map, links with insertOrIgnore; never deletes.
 */
final class KnowledgeBaseImporter implements Importer
{
    private const ENTITY = 'kb';

    private const CHUNK = 200;

    public const SECTION_COLUMNS = [
        'med_wirkmechanismus' => 'med_mechanism_of_action',
        'med_indikationen' => 'med_indications',
        'med_kontraindikationen' => 'med_contraindications',
        'med_uaw' => 'med_side_effects',
        'med_dosierung' => 'med_dosage',
        'med_besonderheiten' => 'med_special_notes',
        'mass_wirkprinzip' => 'mass_principle',
        'mass_indikationen' => 'mass_indications',
        'mass_kontraindikationen' => 'mass_contraindications',
        'mass_risiken' => 'mass_risks',
        'mass_alternativen' => 'mass_alternatives',
        'mass_durchfuehrung' => 'mass_procedure',
    ];

    private const IMAGE_PATH = '~^/storage/kb-images/([a-f0-9]{32})\.(?:jpe?g|png|webp|gif)$~D';

    private const FA_PREFIXES = ['fa', 'fas', 'far', 'fab', 'fal', 'fad', 'fa-solid', 'fa-regular', 'fa-brands', 'fa-light', 'fa-duotone', 'fa-thin'];

    private ImportContext $ctx;

    /** @var array<string, list<int|string>> warning => legacy ids */
    private array $warn = [];

    /** @var array<string, array<string, int>> removed|unwrapped|headings => element name => count over all entries */
    private array $elements = [];

    /** @var array<string, array{0: ?string, 1: ?string}> legacy file name => [new src, reason] */
    private array $images = [];

    private string $current = '';

    /** @var array<int, true> legacy entry ids whose pass-1 write failed in this run (pass 2 skips them) */
    private array $failedEntries = [];

    private ?LegacyLinkRewriter $links = null;

    /** @var ?array{0: string, 1: string} lower-case host and port ('' = default) of the legacy SYSTEM_URL */
    private ?array $legacyHost = null;

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
        return [];
    }

    public function import(ImportContext $ctx): void
    {
        if (! $ctx->legacy()->tableExists('intra_kb_entries')) {
            $ctx->report()->note(self::ENTITY.': legacy table intra_kb_entries missing (plugin knowledge-base not installed), skipped.');

            return;
        }
        $this->ctx = $ctx;
        $this->warn = $this->elements = $this->images = $this->failedEntries = [];
        $this->legacyHost = LegacyLinkRewriter::legacyHost($ctx->legacy());

        DB::transaction(function () {
            $this->categories();
            $this->tags();
            $this->entries();
            $this->contents();
            $this->entryTags();
            $this->relations();
        });

        $report = $ctx->report();
        $tags = [
            'removed' => 'HTML elements removed with their content',
            'unwrapped' => 'HTML elements outside the legacy allowlist unwrapped, text kept',
            'headings' => 'headings outside h2-h4 kept as heading 1 (h1) or 3 (h5, h6)',
        ];
        foreach ($tags as $kind => $what) {
            $counts = $this->elements[$kind] ?? [];
            if ($counts !== []) {
                arsort($counts);
                $top = array_slice($counts, 0, 20, true);
                $list = implode(', ', array_map(fn ($tag, $n) => "{$tag} ({$n})", array_keys($top), $top)).(count($counts) > 20 ? ', ...' : '');
                $report->warnRows("kb entr(y/ies): {$what}: {$list}", array_values(array_unique($this->warn[$kind] ?? [])));
            }
            unset($this->warn[$kind]);
        }
        foreach ($this->warn as $what => $ids) {
            $report->warnRows($what, array_values(array_unique($ids)));
        }
    }

    private function categories(): void
    {
        $legacy = $this->ctx->legacy();
        $report = $this->ctx->report();
        if (! $legacy->tableExists('intra_kb_categories')) {
            $report->note(self::ENTITY.': legacy table intra_kb_categories missing, no categories.');

            return;
        }
        /** @var array<int, ?int> $parents legacy id => legacy parent id */
        $parents = [];
        foreach ($legacy->tableWithInstants('intra_kb_categories', ['created_at'])->orderBy('id')->get() as $row) {
            $report->read('kb_category');
            $id = (int) $row->id;
            $name = mb_substr(trim((string) $row->name), 0, 100);
            if ($name === '') {
                $name = "Kategorie {$id}";
                $this->warn['kb categor(y/ies) without a name, named "Kategorie <legacy id>"'][] = $id;
            }
            $sort = (int) $row->sort_order;
            if ($sort < 0 || $sort > 65535) {
                $this->warn['kb categor(y/ies): sort order outside 0..65535, clamped'][] = $id;
                $sort = max(0, min(65535, $sort));
            }
            $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
            $values = ['name' => $name, 'icon' => $this->icon($row->icon ?? null, $id), 'sort_order' => $sort, 'created_at' => $created, 'updated_at' => $created];
            try {
                DB::transaction(fn () => $this->ctx->ids()->write('kb_category', $id, 'kb_categories', $values));
            } catch (Throwable $e) {
                $report->failed('kb_category', "legacy category {$id}: ".ImportReport::describe($e));

                continue;
            }
            $report->written('kb_category');
            $parents[$id] = $row->parent_id === null ? null : (int) $row->parent_id;
        }

        foreach ($parents as $id => $parent) {
            if ($parent !== null && ! array_key_exists($parent, $parents)) {
                $this->warn['kb categor(y/ies): parent category gone or not imported, now at the top'][] = $id;
                $parents[$id] = null;
            }
        }
        foreach (array_keys($parents) as $start) {
            $seen = [];
            for ($p = $start; $p !== null && ! isset($seen[$p]); $p = $parents[$p]) {
                $seen[$p] = true;
            }
            if ($p !== null) {
                $loop = [];
                $q = $p;
                do {
                    $loop[] = $q;
                    $q = (int) $parents[$q];
                } while ($q !== $p);
                $parents[min($loop)] = null;
                $this->warn['kb categor(y/ies): parent loop broken, now at the top'][] = min($loop);
            }
        }

        $map = $this->ctx->ids()->forEntity('kb_category');
        foreach ($parents as $id => $parent) {
            DB::table('kb_categories')->where('id', $map[$id])->update(['parent_id' => $parent === null ? null : $map[$parent]]);
        }
    }

    private function icon(mixed $raw, int $id): ?string
    {
        $raw = strtolower(trim((string) $raw));
        foreach (array_reverse(preg_split('/\s+/', $raw) ?: []) as $token) {
            if (in_array($token, self::FA_PREFIXES, true)) {
                continue;
            }
            $name = (string) preg_replace('/^fa-/', '', $token);
            if (strlen($name) <= 50 && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $name) === 1) {
                return $name;
            }
            break;
        }
        if ($raw !== '') {
            $this->warn['kb categor(y/ies): icon is no Font Awesome or Lucide name, imported without icon'][] = $id;
        }

        return null;
    }

    private function tags(): void
    {
        $legacy = $this->ctx->legacy();
        $report = $this->ctx->report();
        if (! $legacy->tableExists('intra_kb_tags')) {
            $report->note(self::ENTITY.': legacy table intra_kb_tags missing, no tags.');

            return;
        }
        /** @var array<string, int> $byName lower-case name => tag id (the new instance's and this run's) */
        $byName = [];
        foreach (KbTag::query()->orderBy('id')->get(['id', 'name']) as $tag) {
            $byName[mb_strtolower($tag->name)] ??= $tag->id;
        }
        $claimed = [];
        foreach ($legacy->tableWithInstants('intra_kb_tags', ['created_at'])->orderBy('id')->get() as $row) {
            $report->read('kb_tag');
            $id = (int) $row->id;
            $name = mb_substr(trim((string) $row->name), 0, 50);
            if ($name === '') {
                $report->skippedCount('kb_tag', 1);
                $this->warn['kb tag(s) without a name skipped'][] = $id;

                continue;
            }
            $key = mb_strtolower($name);
            $mapped = $this->ctx->ids()->get('kb_tag', $id);
            $same = $byName[$key] ?? null;
            if ($same !== null) {
                $this->ctx->ids()->put('kb_tag', $id, $same);
                $report->written('kb_tag');
                if ($same !== $mapped || isset($claimed[$same])) {
                    $this->warn['kb tag(s) merged into a tag with the same name (ignoring case)'][] = $id;
                }
                $claimed[$same] = true;

                continue;
            }
            $color = self::color($row->color);
            if ($color === null) {
                $this->warn['kb tag(s): colour is no #rrggbb, set to the default colour'][] = $id;
            }
            $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
            $values = ['name' => $name, 'color' => $color ?? KbTag::DEFAULT_COLOR, 'created_at' => $created, 'updated_at' => $created];
            try {
                try {
                    $newId = DB::transaction(fn () => $this->ctx->ids()->write('kb_tag', $id, 'kb_tags', $values));
                } catch (UniqueConstraintViolationException $e) {
                    $newId = (int) (KbTag::query()->where('name', $name)->value('id') ?? throw $e);
                    $this->ctx->ids()->put('kb_tag', $id, $newId);
                    $this->warn['kb tag(s) merged into a tag with the same name (ignoring case)'][] = $id;
                }
            } catch (Throwable $e) {
                $report->failed('kb_tag', "legacy tag {$id}: ".ImportReport::describe($e));

                continue;
            }
            $report->written('kb_tag');
            $byName[$key] = $claimed[$newId] = $newId;
        }
    }

    private static function color(mixed $raw): ?string
    {
        $color = strtolower(trim((string) $raw));
        if (preg_match('/^#[0-9a-f]{6}$/D', $color) === 1) {
            return $color;
        }

        return preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/D', $color, $m) === 1 ? "#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}" : null;
    }

    private function entries(): void
    {
        $users = $this->ctx->ids()->forEntity('user');
        $categories = $this->ctx->ids()->forEntity('kb_category');
        $report = $this->ctx->report();
        $this->ctx->legacy()->tableWithInstants('intra_kb_entries', ['created_at', 'updated_at'])
            ->chunkById(self::CHUNK, function ($rows) use ($users, $categories, $report) {
                foreach ($rows as $row) {
                    $report->read('kb_entry');
                    $id = (int) $row->id;
                    $problems = [];
                    $type = (string) $row->type;
                    if (! in_array($type, KbEntry::TYPES, true)) {
                        $problems[] = 'unknown type, set to general';
                        $type = 'general';
                    }
                    $level = $row->competency_level === null || $row->competency_level === '' ? null : (string) $row->competency_level;
                    if ($level !== null && ! in_array($level, KbEntry::COMPETENCY_LEVELS, true)) {
                        $problems[] = 'unknown competency level, left empty';
                        $level = null;
                    }
                    $legacyCategory = $row->category_id ?? null;
                    $category = $legacyCategory === null ? null : ($categories[(string) $legacyCategory] ?? null);
                    if ($legacyCategory !== null && $category === null) {
                        $problems[] = 'category gone or not imported, left empty';
                    }
                    $user = function (mixed $legacyUser, string $what) use ($users, &$problems): ?int {
                        $new = $legacyUser === null ? null : ($users[(string) $legacyUser] ?? null);
                        if ($legacyUser !== null && $new === null) {
                            $problems[] = "{$what} account not imported, left empty";
                        }

                        return $new;
                    };
                    foreach (['title' => $row->title, 'subtitle' => $row->subtitle, 'med_wirkstoff' => $row->med_wirkstoff ?? null, 'med_wirkstoffgruppe' => $row->med_wirkstoffgruppe ?? null] as $column => $value) {
                        if (mb_strlen(trim((string) $value)) > 255) {
                            $problems[] = "{$column} longer than 255 characters, cut";
                        }
                    }
                    $title = mb_substr(trim((string) $row->title), 0, 255);
                    if ($title === '') {
                        $problems[] = 'empty title, set to "Eintrag <legacy id>"';
                        $title = "Eintrag {$id}";
                    }
                    $created = LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString();
                    $values = [
                        'type' => $type,
                        'category_id' => $category,
                        'title' => $title,
                        'subtitle' => self::text($row->subtitle),
                        'competency_level' => $level,
                        'active_ingredient' => self::text($row->med_wirkstoff ?? null),
                        'drug_class' => self::text($row->med_wirkstoffgruppe ?? null),
                        'search_text' => $title,
                        'is_pinned' => (bool) ($row->is_pinned ?? false),
                        'is_archived' => (bool) ($row->is_archived ?? false),
                        'hide_editor' => (bool) ($row->hide_editor ?? false),
                        'created_by' => $user($row->created_by, 'creating'),
                        'updated_by' => $user($row->updated_by, 'editing'),
                        'created_at' => $created,
                        'updated_at' => LegacyClock::instant($row, 'updated_at') ?? $created,
                    ];
                    try {
                        DB::transaction(fn () => $this->ctx->ids()->write('kb_entry', $id, 'kb_entries', $values));
                    } catch (Throwable $e) {
                        $report->failed('kb_entry', "legacy entry {$id}: ".ImportReport::describe($e));
                        $this->failedEntries[$id] = true;

                        continue;
                    }
                    foreach ($problems as $what) {
                        $this->warn["kb entr(y/ies): {$what}"][] = $id;
                    }
                }
            });
    }

    private static function text(mixed $value): ?string
    {
        $value = mb_substr(trim((string) $value), 0, 255);

        return $value === '' ? null : $value;
    }

    private function contents(): void
    {
        $report = $this->ctx->report();
        $entries = $this->ctx->ids()->forEntity('kb_entry');
        $this->links = LegacyLinkRewriter::fromIds($this->ctx->ids());
        $converter = new LegacyHtmlToTiptap(fn (string $href) => $this->href($href), fn (string $src) => $this->imageSrc($src));
        $this->ctx->legacy()->table('intra_kb_entries')->chunkById(self::CHUNK, function ($rows) use ($report, $entries, $converter) {
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $newId = $entries[(string) $id] ?? null;
                if ($newId === null || isset($this->failedEntries[$id])) {
                    continue;
                }
                $this->current = (string) $id;
                try {
                    $convert = function (mixed $html) use ($converter, $id): ?array {
                        if ($html === null || trim((string) $html) === '') {
                            return null;
                        }
                        $doc = $converter->convert((string) $html);
                        foreach ($converter->dropped as $tag => $n) {
                            $kind = in_array($tag, LegacyHtmlToTiptap::DROP, true) ? 'removed' : 'unwrapped';
                            $this->elements[$kind][$tag] = ($this->elements[$kind][$tag] ?? 0) + $n;
                            $this->warn[$kind][] = $id;
                        }
                        if ($converter->droppedLinks > 0) {
                            $this->warn['kb entr(y/ies): links with a target other than http(s), mailto or a path removed, text kept'][] = $id;
                        }
                        foreach ($converter->headings as $tag => $n) {
                            $this->elements['headings'][$tag] = ($this->elements['headings'][$tag] ?? 0) + $n;
                            $this->warn['headings'][] = $id;
                        }
                        if ($converter->truncatedDepth > 0) {
                            $this->warn['kb entr(y/ies): content cut, nested deeper than '.KbContentValidator::MAX_DEPTH.' levels'][] = $id;
                        }
                        if ($converter->truncatedNodes > 0) {
                            $this->warn['kb entr(y/ies): content cut, more than '.KbContentValidator::MAX_NODES.' nodes in one field'][] = $id;
                        }
                        if ($converter->droppedImages > 0) {
                            $this->warn['kb entr(y/ies): images that are no knowledge base upload (/storage/kb-images/<32 hex>) removed'][] = $id;
                        }

                        return $doc;
                    };
                    $content = $convert($row->content);
                    $sections = [];
                    foreach (self::SECTION_COLUMNS as $column => $key) {
                        $doc = $convert($row->{$column} ?? null);
                        if ($doc !== null && $doc['content'] !== []) {
                            $sections[$key] = $doc;
                        }
                    }
                    /** @var KbEntry $entry */
                    $entry = KbEntry::query()->findOrFail($newId);
                    $entry->forceFill(['content' => $content, 'sections' => $sections === [] ? null : $sections]);
                    $entry->search_text = $entry->buildSearchText();
                    $entry->timestamps = false;
                    DB::transaction(fn () => $entry->save());
                } catch (InvalidArgumentException $e) {
                    $report->failed('kb_entry', "legacy entry {$id}: content not converted ({$e->getMessage()}), ".$this->keepPrevious($newId));

                    continue;
                } catch (Throwable $e) {
                    $report->failed('kb_entry', "legacy entry {$id}: ".ImportReport::describe($e));

                    continue;
                }
                $report->written('kb_entry');
            }
        });
    }

    private function keepPrevious(int $newId): string
    {
        $entry = KbEntry::query()->find($newId);
        if ($entry === null) {
            return 'the entry is gone';
        }
        try {
            $entry->search_text = $entry->buildSearchText();
            $entry->timestamps = false;
            DB::transaction(fn () => $entry->save());
        } catch (Throwable) {
        }

        return $entry->content === null && $entry->sections === null
            ? 'the entry was imported WITHOUT content (title and other columns only)'
            : 'the entry keeps its previous content';
    }

    private function href(string $href): ?string
    {
        if (preg_match('~^https?://~i', $href) === 1) {
            $local = $this->localPath($href);
            if ($local === null) {
                return $href;
            }
            $href = $local;
        } elseif (! str_starts_with($href, '/')) {
            if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href) === 1 || in_array($href[0] ?? '', ['#', '?'], true)) {
                return $href;
            }
            $relative = (string) preg_replace('~^(?:\./)+~', '', $href);
            if (preg_match('~^(?:lexicon|wissensdb)/~', $relative) === 1) {
                $href = '/'.$relative;
            } elseif (preg_match('~^(?:view|edit|index|manage-taxonomy)(?:\.php)?(?:[?#]|$)~', $relative) === 1) {
                $href = '/lexicon/'.$relative;
            } else {
                return $href;
            }
        }

        [$link, $outcome] = $this->links?->notificationLink($href) ?? [null, 'dropped'];
        if ($outcome === 'unmapped' && str_starts_with((string) $link, '/lexicon')) {
            $this->warn['kb entr(y/ies): links to knowledge base entries that were not imported removed, text kept'][] = $this->current;

            return null;
        }
        if ($outcome === 'unmapped') {
            $this->warn['kb entr(y/ies): links to records that were not imported now open the list page'][] = $this->current;
        }
        if ($link === null) {
            $this->warn['kb entr(y/ies): links with a target other than http(s), mailto or a path removed, text kept'][] = $this->current;
        }

        return $link;
    }

    private function imageSrc(string $src): ?string
    {
        $path = $src;
        if (preg_match('~^https?://~i', $src) === 1) {
            $path = $this->localPath($src);
            if ($path === null) {
                return $src;
            }
        }
        $path = explode('#', explode('?', $path, 2)[0], 2)[0];
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        $path = LegacyLinkRewriter::withoutBasePath($path);
        if (preg_match(self::IMAGE_PATH, $path, $m) !== 1) {
            return $src;
        }
        $name = basename($path);
        $this->images[$name] ??= $this->copyImage($name, $m[1]);
        [$new, $reason] = $this->images[$name];
        if ($new === null) {
            $this->warn["kb entr(y/ies): image removed, {$reason}"][] = $this->current;
        }

        return $new;
    }

    /** @return array{0: ?string, 1: ?string} [new src, why not] */
    private function copyImage(string $name, string $hex): array
    {
        $disk = Storage::disk(KbImageController::DISK);
        $uploads = $this->ctx->uploadsDir();
        if ($uploads === null) {
            foreach (array_unique(KbImageController::TYPES) as $ext) {
                if ($disk->exists("{$hex}.{$ext}")) {
                    return ["/storage/kb-images/{$hex}.{$ext}", null];
                }
            }

            return [null, 'file not copied (no --uploads)'];
        }
        $base = realpath($uploads.'/storage/kb-images');
        if ($base === false) {
            return [null, 'folder storage/kb-images missing below --uploads'];
        }
        $path = realpath($base.DIRECTORY_SEPARATOR.$name);
        if ($path === false || ! is_file($path)) {
            return [null, 'file missing below --uploads (storage/kb-images)'];
        }
        if (! str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return [null, 'file outside storage/kb-images (symlink)'];
        }
        try {
            $ext = ImageUpload::check($path, KbImageController::MAX_BYTES, KbImageController::TYPES);
            $bytes = ImageUpload::clean($path, KbImageController::MAX_BYTES);
        } catch (UploadRejected $e) {
            return [null, 'file refused: '.$e->getMessage()];
        }
        $target = "{$hex}.{$ext}";
        $src = "/storage/kb-images/{$target}";
        if ($disk->exists($target) && hash('sha256', (string) $disk->get($target)) === hash('sha256', $bytes)) {
            return [$src, null];
        }
        if ($this->ctx->dryRun()) {
            return [$src, null];
        }

        return $disk->put($target, $bytes) ? [$src, null] : [null, 'copy failed'];
    }

    private function localPath(string $url): ?string
    {
        return LegacyLinkRewriter::localPath($url, $this->legacyHost);
    }

    private function entryTags(): void
    {
        $legacy = $this->ctx->legacy();
        $report = $this->ctx->report();
        if (! $legacy->tableExists('intra_kb_entry_tags')) {
            return;
        }
        $entries = $this->ctx->ids()->forEntity('kb_entry');
        $tags = $this->ctx->ids()->forEntity('kb_tag');
        $rows = [];
        foreach ($legacy->table('intra_kb_entry_tags')->orderBy('entry_id')->orderBy('tag_id')->get() as $row) {
            $report->read('kb_entry_tag');
            $entry = $entries[(string) $row->entry_id] ?? null;
            $tag = $tags[(string) $row->tag_id] ?? null;
            if ($entry === null || $tag === null) {
                $report->skippedCount('kb_entry_tag', 1);
                $this->warn['kb entry tag link(s) skipped (listed by legacy entry): entry or tag not imported'][] = (int) $row->entry_id;

                continue;
            }
            if (isset($rows["{$entry}-{$tag}"])) {
                $report->skippedCount('kb_entry_tag', 1);
                $this->warn['kb entry tag link(s) skipped (listed by legacy entry): the same tag twice after merging tags'][] = (int) $row->entry_id;

                continue;
            }
            $rows["{$entry}-{$tag}"] = ['entry_id' => $entry, 'tag_id' => $tag];
        }
        $this->insertLinks('kb_entry_tag', 'kb_entry_tag', array_values($rows));
    }

    private function relations(): void
    {
        $legacy = $this->ctx->legacy();
        $report = $this->ctx->report();
        if (! $legacy->tableExists('intra_kb_entry_relations')) {
            return;
        }
        $entries = $this->ctx->ids()->forEntity('kb_entry');
        $rows = [];
        foreach ($legacy->tableWithInstants('intra_kb_entry_relations', ['created_at'])->orderBy('entry_id')->orderBy('related_entry_id')->get() as $row) {
            $report->read('kb_relation');
            $a = $entries[(string) $row->entry_id] ?? null;
            $b = $entries[(string) $row->related_entry_id] ?? null;
            $why = match (true) {
                (int) $row->entry_id === (int) $row->related_entry_id => 'an entry related to itself',
                $a === null || $b === null => 'entry not imported',
                isset($rows["{$a}-{$b}"]) || isset($rows["{$b}-{$a}"]) => 'the same pair in both directions',
                default => null,
            };
            if ($why !== null) {
                $report->skippedCount('kb_relation', 1);
                $this->warn["kb relation(s) skipped (listed by legacy entry): {$why}"][] = (int) $row->entry_id;

                continue;
            }
            /** @var int $a */
            /** @var int $b */
            [$x, $y] = KbEntryRelation::normalize($a, $b);
            $rows["{$x}-{$y}"] = ['entry_id' => $x, 'related_entry_id' => $y, 'created_at' => LegacyClock::instant($row, 'created_at') ?? now()->toDateTimeString()];
        }
        $this->insertLinks('kb_relation', 'kb_entry_relations', array_values($rows));
    }

    /**
     * insertOrIgnore in blocks (a re-run adds nothing twice); a failing block is retried row by row. $rows are unique
     * pairs, each counts as written (also one a previous run stored).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertLinks(string $reportEntity, string $table, array $rows): void
    {
        $report = $this->ctx->report();
        foreach (array_chunk($rows, 500) as $chunk) {
            try {
                DB::transaction(fn () => DB::table($table)->insertOrIgnore($chunk));
                $report->written($reportEntity, count($chunk));

                continue;
            } catch (Throwable) {
            }
            foreach ($chunk as $row) {
                try {
                    DB::transaction(fn () => DB::table($table)->insertOrIgnore($row));
                    $report->written($reportEntity);
                } catch (Throwable $e) {
                    $report->failed($reportEntity, json_encode(array_intersect_key($row, ['entry_id' => 1, 'tag_id' => 1, 'related_entry_id' => 1])).': '.ImportReport::describe($e));
                }
            }
        }
    }
}
