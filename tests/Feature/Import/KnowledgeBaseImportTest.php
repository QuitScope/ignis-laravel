<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\KnowledgeBase\Content\KbContentValidator;
use Modules\KnowledgeBase\Models\KbCategory;
use Modules\KnowledgeBase\Models\KbEntry;
use Modules\KnowledgeBase\Models\KbTag;
use Tests\Support\LegacyDatabase;
use Tests\Support\LegacySeed;

uses(RefreshDatabase::class)->group('legacy');

const KBI_PNG = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const KBI_JPG_AS_PNG = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const KBI_MISSING = 'cccccccccccccccccccccccccccccccc';
const KBI_SVG = 'dddddddddddddddddddddddddddddddd';
const KBI_HUGE = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

beforeEach(function () {
    if (! LegacySeed::boot()) {
        $this->markTestSkipped('LEGACY_TEST_DB_HOST not set');
    }
    LegacySeed::kbQuirks();
    $this->seed(RoleSeeder::class);
    Storage::fake('kb-images');
    config(['ignis.import.legacy_base_path' => '/intra/']);
    $this->uploads = sys_get_temp_dir().'/ignis-kb-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->uploads.'/storage/kb-images');
    $dir = $this->uploads.'/storage/kb-images';
    $img = imagecreatetruecolor(4, 3);
    ob_start();
    imagepng($img);
    File::put("{$dir}/".KBI_PNG.'.png', (string) ob_get_clean());
    ob_start();
    imagejpeg($img);
    File::put("{$dir}/".KBI_JPG_AS_PNG.'.png', (string) ob_get_clean());
    File::put("{$dir}/".KBI_SVG.'.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    File::put("{$dir}/".KBI_HUGE.'.png', str_repeat('x', 5 * 1024 * 1024 + 1));
    File::put($this->uploads.'/storage/secret.png', 'outside');
});

afterEach(function () {
    if (isset($this->uploads)) {
        File::deleteDirectory($this->uploads);
    }
    if (LegacyDatabase::available()) {
        LegacyDatabase::connection()->statement('SET SESSION sql_mode = DEFAULT');
        LegacySeed::kbQuirksUndo();
    }
});

function kbiSeed(): void
{
    LegacySeed::users([['id' => 1], ['id' => 2]]);
    LegacySeed::config(['SYSTEM_URL' => 'Intra.Example.org']);
    LegacySeed::kbCategories([
        ['id' => 1, 'name' => 'Medikamente', 'icon' => 'fa-solid fa-pills', 'sort_order' => 2],
        ['id' => 2, 'parent_id' => 1, 'name' => 'Analgetika', 'icon' => 'fas fa-<script>', 'sort_order' => -5],
        ['id' => 3, 'parent_id' => 3, 'name' => 'Selbst'],
        ['id' => 4, 'parent_id' => 5, 'name' => 'Kreis A'],
        ['id' => 5, 'parent_id' => 4, 'name' => 'Kreis B'],
        ['id' => 6, 'parent_id' => 99, 'name' => 'Waise'],
    ]);
    LegacySeed::kbTags([
        ['id' => 1, 'name' => 'Notfall', 'color' => '#FF0000'],
        ['id' => 2, 'name' => 'notfall ', 'color' => '#00ff00'],
        ['id' => 3, 'name' => 'Kurz', 'color' => '#abc'],
        ['id' => 4, 'name' => 'Kaputt', 'color' => 'red;}'],
    ]);
    LegacyDatabase::connection()->statement("SET SESSION sql_mode = ''");
    $img = fn (string $hex, string $prefix = '/intra') => "{$prefix}/storage/kb-images/{$hex}.png";
    LegacySeed::kbEntries([
        ['id' => 1, 'type' => 'medication', 'category_id' => 2, 'title' => 'Paracetamol', 'subtitle' => 'Analgetikum', 'competency_level' => 'notsan_2c',
            'med_wirkstoff' => ' Paracetamol ', 'med_wirkstoffgruppe' => 'Nichtopioid', 'med_dosierung' => '<p>1 g i.v.</p>', 'med_uaw' => '   ',
            'is_pinned' => 1, 'hide_editor' => 1, 'created_by' => 1, 'updated_by' => 2,
            'content' => '<h2>Hinweise</h2><p>Siehe <a href="/intra/lexicon/view?id=3">drei</a>, <a href="https://intra.example.org/intra/lexicon/view.php?id=2">zwei</a>, '
                .'<a href="view?id=3#teil">relativ</a>, <a href="/lexicon/view?id=99">weg</a>, <a href="javascript:alert(1)">js</a>, '
                .'<a href="https://other.example/x" target="_blank">extern</a></p><script>alert(1)</script><div>Block</div>'
                .'<img src="'.$img(KBI_PNG).'" alt="Bild"><img src="https://INTRA.example.org'.$img(KBI_JPG_AS_PNG).'">'
                .'<img src="'.$img(KBI_MISSING).'"><img src="'.$img(KBI_SVG, '').'"><img src="'.$img(KBI_HUGE).'">'
                .'<img src="/storage/kb-images/../secret.png"><img src="data:image/png;base64,AAAA"><img src="https://other.example'.$img(KBI_PNG, '').'">'],
        ['id' => 2, 'title' => 'Allgemein', 'category_id' => 6, 'mass_risiken' => '<ul><li>Risiko</li></ul>', 'med_indikationen' => '<p>Schmerz</p>',
            'is_archived' => 1, 'created_by' => 77],
        ['id' => 3, 'type' => 'measure', 'title' => 'Intubation', 'category_id' => 42, 'content' => null, 'mass_durchfuehrung' => '<ol><li>Vorbereiten</li></ol>'],
        ['id' => 4, 'type' => '', 'title' => '  ', 'competency_level' => '', 'content' => '<p>a</p>'],
    ]);
    LegacySeed::kbEntryTags([[1, 1], [1, 2], [2, 3], [3, 4], [1, 99], [99, 1]]);
    LegacySeed::kbRelations([[1, 3], [3, 1], [2, 2], [1, 99], [2, 3]]);
}

function kbiId(string $entity, int $legacyId): ?int
{
    $id = DB::table('legacy_id_map')->where(['entity' => $entity, 'legacy_id' => (string) $legacyId])->value('new_id');

    return $id === null ? null : (int) $id;
}

function kbiEntry(int $legacyId): KbEntry
{
    return KbEntry::findOrFail(kbiId('kb_entry', $legacyId));
}

/** @return array{links: list<?string>, images: list<string>, texts: list<string>} */
function kbiWalk(?array $doc): array
{
    $out = ['links' => [], 'images' => [], 'texts' => []];
    $walk = function (array $nodes) use (&$walk, &$out): void {
        foreach ($nodes as $n) {
            if ($n['type'] === 'image') {
                $out['images'][] = $n['attrs']['src'];
            }
            if (isset($n['text'])) {
                $out['texts'][] = $n['text'];
                $link = collect($n['marks'] ?? [])->firstWhere('type', 'link');
                $out['links'][] = $link['attrs']['href'] ?? null;
            }
            $walk($n['content'] ?? []);
        }
    };
    $walk($doc['content'] ?? []);

    return $out;
}

/** @return array<string, mixed> every target table and the stored files */
function kbiSnapshot(): array
{
    $tables = collect(['kb_categories', 'kb_tags', 'kb_entries', 'kb_entry_tag', 'kb_entry_relations', 'legacy_id_map'])
        ->mapWithKeys(fn (string $t) => [$t => DB::table($t)->get()->map(fn ($r) => (array) $r)->sortBy(fn ($r) => json_encode($r))->values()->all()])->all();

    return $tables + ['files' => collect(Storage::disk('kb-images')->allFiles())->sort()->values()->all()];
}

function kbiWarnings(array $json): string
{
    return implode("\n", $json['warnings']);
}

it('imports categories, tags, entries, tag links and relations with counted warnings', function () {
    kbiSeed();

    $json = LegacySeed::run(['kb'], uploads: $this->uploads);
    $w = kbiWarnings($json);

    expect($json['exit'])->toBe(0)
        ->and($json['entities']['kb_category'])->toBe(['read' => 6, 'written' => 6, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['kb_tag'])->toBe(['read' => 4, 'written' => 4, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['kb_entry'])->toBe(['read' => 4, 'written' => 4, 'skipped' => 0, 'failed' => 0])
        ->and($json['entities']['kb_entry_tag'])->toBe(['read' => 6, 'written' => 3, 'skipped' => 3, 'failed' => 0])
        ->and($json['entities']['kb_relation'])->toBe(['read' => 5, 'written' => 2, 'skipped' => 3, 'failed' => 0]);

    $cat = fn (int $id) => KbCategory::findOrFail(kbiId('kb_category', $id));
    expect($cat(2)->parent_id)->toBe($cat(1)->id)
        ->and($cat(1)->icon)->toBe('pills')->and($cat(2)->icon)->toBeNull()
        ->and($cat(1)->sort_order)->toBe(2)->and($cat(2)->sort_order)->toBe(0)
        ->and($cat(3)->parent_id)->toBeNull()
        ->and($cat(4)->parent_id)->toBeNull()->and($cat(5)->parent_id)->toBe($cat(4)->id)
        ->and($cat(6)->parent_id)->toBeNull()
        ->and($w)->toContain('2 kb categor(y/ies): parent loop broken, now at the top (legacy ids: 3, 4)')
        ->toContain('1 kb categor(y/ies): parent category gone or not imported, now at the top (legacy ids: 6)')
        ->toContain('1 kb categor(y/ies): sort order outside 0..65535, clamped (legacy ids: 2)')
        ->toContain('icon is no Font Awesome or Lucide name, imported without icon (legacy ids: 2)');

    expect(KbTag::orderBy('id')->get(['name', 'color'])->toArray())->toBe([
        ['name' => 'Notfall', 'color' => '#ff0000'], ['name' => 'Kurz', 'color' => '#aabbcc'], ['name' => 'Kaputt', 'color' => KbTag::DEFAULT_COLOR],
    ])->and(kbiId('kb_tag', 2))->toBe(kbiId('kb_tag', 1))
        ->and($w)->toContain('1 kb tag(s) merged into a tag with the same name (ignoring case) (legacy ids: 2)')
        ->toContain('1 kb tag(s): colour is no #rrggbb, set to the default colour (legacy ids: 4)');

    $e1 = kbiEntry(1);
    $user = fn (int $id) => kbiId('user', $id);
    expect($e1->only(['type', 'title', 'subtitle', 'competency_level', 'active_ingredient', 'drug_class', 'is_pinned', 'is_archived', 'hide_editor']))->toBe([
        'type' => 'medication', 'title' => 'Paracetamol', 'subtitle' => 'Analgetikum', 'competency_level' => 'notsan_2c',
        'active_ingredient' => 'Paracetamol', 'drug_class' => 'Nichtopioid', 'is_pinned' => true, 'is_archived' => false, 'hide_editor' => true,
    ])->and($e1->category_id)->toBe($cat(2)->id)
        ->and($e1->created_by)->toBe($user(1))->and($e1->updated_by)->toBe($user(2))
        ->and($e1->created_at->utc()->format('Y-m-d H:i'))->toBe('2024-03-02 10:00')
        ->and($e1->updated_at->utc()->format('Y-m-d H:i'))->toBe('2024-03-03 11:00');

    $c = kbiWalk($e1->content);
    $e2 = kbiId('kb_entry', 2);
    $e3 = kbiId('kb_entry', 3);
    expect(KbContentValidator::validate($e1->content))->toBe($e1->content)
        ->and($e1->content['content'][0])->toBe(['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Hinweise']]])
        ->and(array_combine($c['texts'], $c['links']))->toMatchArray([
            'drei' => "/lexicon/{$e3}", 'zwei' => "/lexicon/{$e2}", 'relativ' => "/lexicon/{$e3}", 'extern' => 'https://other.example/x', 'Block' => null,
        ])
        ->and(array_values(array_filter($c['links'])))->toHaveCount(4)
        ->and(implode('', $c['texts']))->toContain(', weg, js, ')->not->toContain('alert(1)')
        ->and($c['images'])->toBe(['/storage/kb-images/'.KBI_PNG.'.png', '/storage/kb-images/'.KBI_JPG_AS_PNG.'.jpg'])
        ->and(Storage::disk('kb-images')->allFiles())->toEqualCanonicalizing([KBI_PNG.'.png', KBI_JPG_AS_PNG.'.jpg'])
        ->and(array_slice(getimagesizefromstring((string) Storage::disk('kb-images')->get(KBI_PNG.'.png')), 0, 2))->toBe([4, 3])
        ->and($e1->sections)->toBe(['med_dosage' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '1 g i.v.']]]]]])
        ->and($e1->search_text)->toBe($e1->buildSearchText())->toContain('Paracetamol')->toContain('1 g i.v.')->toContain('Hinweise');

    expect($w)->toContain('1 kb entr(y/ies): links to knowledge base entries that were not imported removed, text kept (legacy ids: 1)')
        ->toContain('1 kb entr(y/ies): links with a target other than http(s), mailto or a path removed, text kept (legacy ids: 1)')
        ->toContain('1 kb entr(y/ies): HTML elements removed with their content: script (1) (legacy ids: 1)')
        ->toContain('1 kb entr(y/ies): HTML elements outside the legacy allowlist unwrapped, text kept: div (1) (legacy ids: 1)')
        ->not->toContain('content cut')
        ->toContain('1 kb entr(y/ies): image removed, file missing below --uploads (storage/kb-images) (legacy ids: 1)')
        ->toContain('kb entr(y/ies): image removed, file refused: ')
        ->toContain('1 kb entr(y/ies): images that are no knowledge base upload (/storage/kb-images/<32 hex>) removed (legacy ids: 1)');

    $two = kbiEntry(2);
    expect($two->type)->toBe('general')->and(array_keys($two->sections))->toBe(['med_indications', 'mass_risks'])
        ->and($two->category_id)->toBe($cat(6)->id)->and($two->is_archived)->toBeTrue()->and($two->created_by)->toBeNull()
        ->and($two->content)->toBe(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Inhalt 2']]]]])
        ->and($two->search_text)->toBe("Allgemein\nInhalt 2\nSchmerz\nRisiko")
        ->and($w)->toContain('1 kb entr(y/ies): creating account not imported, left empty (legacy ids: 2)');

    $three = kbiEntry(3);
    $four = kbiEntry(4);
    expect($three->category_id)->toBeNull()->and($three->content)->toBeNull()->and(array_keys($three->sections))->toBe(['mass_procedure'])
        ->and($four->type)->toBe('general')->and($four->title)->toBe('Eintrag 4')->and($four->competency_level)->toBeNull()
        ->and($w)->toContain('1 kb entr(y/ies): category gone or not imported, left empty (legacy ids: 3)')
        ->toContain('1 kb entr(y/ies): unknown type, set to general (legacy ids: 4)')
        ->toContain('1 kb entr(y/ies): empty title, set to "Eintrag <legacy id>" (legacy ids: 4)');

    $tag = fn (int $id) => kbiId('kb_tag', $id);
    expect(DB::table('kb_entry_tag')->orderBy('entry_id')->orderBy('tag_id')->get()->map(fn ($r) => [$r->entry_id, $r->tag_id])->all())
        ->toBe([[$e1->id, $tag(1)], [$e2, $tag(3)], [$e3, $tag(4)]])
        ->and(DB::table('kb_entry_relations')->orderBy('entry_id')->get()->map(fn ($r) => [$r->entry_id, $r->related_entry_id])->all())
        ->toBe([[min($e1->id, $e3), max($e1->id, $e3)], [min($e2, $e3), max($e2, $e3)]])
        ->and($w)->toContain('2 kb entry tag link(s) skipped (listed by legacy entry): entry or tag not imported (legacy ids: 1, 99)')
        ->toContain('1 kb entry tag link(s) skipped (listed by legacy entry): the same tag twice after merging tags (legacy ids: 1)')
        ->toContain('1 kb relation(s) skipped (listed by legacy entry): the same pair in both directions (legacy ids: 3)')
        ->toContain('1 kb relation(s) skipped (listed by legacy entry): an entry related to itself (legacy ids: 2)')
        ->toContain('1 kb relation(s) skipped (listed by legacy entry): entry not imported (legacy ids: 1)');
});

it('writes the same state on a second run and brings back a deleted entry', function () {
    kbiSeed();
    LegacySeed::run(['kb'], uploads: $this->uploads);
    $before = kbiSnapshot();

    $again = LegacySeed::run(['kb'], uploads: $this->uploads);
    expect($again['exit'])->toBe(0)->and(kbiSnapshot())->toBe($before);

    kbiEntry(3)->delete();
    LegacySeed::run(['kb'], uploads: $this->uploads);
    $back = kbiEntry(1);
    expect(KbEntry::count())->toBe(4)
        ->and(kbiWalk($back->content)['links'])->toContain('/lexicon/'.kbiId('kb_entry', 3))
        ->and(DB::table('kb_entry_relations')->count())->toBe(2);
});

it('leaves no rows and no files on a dry run', function () {
    kbiSeed();

    $json = LegacySeed::run(['kb'], dry: true, uploads: $this->uploads);

    expect($json['exit'])->toBe(0)->and($json['dry_run'])->toBeTrue()
        ->and($json['entities']['kb_entry']['written'])->toBe(4)
        ->and(collect(['kb_categories', 'kb_tags', 'kb_entries', 'kb_entry_tag', 'kb_entry_relations'])->sum(fn ($t) => DB::table($t)->count()))->toBe(0)
        ->and(DB::table('legacy_id_map')->where('entity', 'like', 'kb_%')->count())->toBe(0)
        ->and(Storage::disk('kb-images')->allFiles())->toBe([]);
});

it('keeps images copied earlier when run without --uploads and reports the others', function () {
    kbiSeed();
    $first = LegacySeed::run(['kb']);
    expect(kbiWalk(kbiEntry(1)->content)['images'])->toBe([])
        ->and(kbiWarnings($first))->toContain('kb entr(y/ies): image removed, file not copied (no --uploads) (legacy ids: 1)');

    LegacySeed::run(['kb'], uploads: $this->uploads);
    LegacySeed::run(['kb']);
    expect(kbiWalk(kbiEntry(1)->content)['images'])->toBe(['/storage/kb-images/'.KBI_PNG.'.png', '/storage/kb-images/'.KBI_JPG_AS_PNG.'.jpg']);
});

it('rewrites links only on the legacy host and without BASE_PATH when none is configured', function () {
    config(['ignis.import.legacy_base_path' => '']);
    LegacySeed::config(['SYSTEM_URL' => 'https://kb.example.org:8443/']);
    LegacySeed::kbEntries([
        ['id' => 1, 'content' => '<p><a href="https://kb.example.org:8443/lexicon/view?id=2">a</a> <a href="https://kb.example.org/lexicon/view?id=2">b</a> '
            .'<a href="/wissensdb/view.php?id=2">c</a> <a href="/intra/lexicon/view?id=2">d</a> <a href="lexicon/edit?id=2">e</a></p>'],
        ['id' => 2],
    ]);

    LegacySeed::run(['kb']);

    $two = kbiId('kb_entry', 2);
    $c = kbiWalk(kbiEntry(1)->content);
    expect(array_combine($c['texts'], $c['links']))->toMatchArray([
        'a' => "/lexicon/{$two}", 'b' => 'https://kb.example.org/lexicon/view?id=2', 'c' => "/lexicon/{$two}",
        'd' => '/intra/lexicon/view?id=2', 'e' => "/lexicon/{$two}/edit",
    ]);
});

it('merges a legacy tag into an existing tag of the new instance', function () {
    $existing = KbTag::forceCreate(['name' => 'NOTFALL', 'color' => '#123456']);
    LegacySeed::kbTags([['id' => 1, 'name' => 'Notfall', 'color' => '#ff0000']]);
    LegacySeed::kbEntries([['id' => 1]]);
    LegacySeed::kbEntryTags([[1, 1]]);

    LegacySeed::run(['kb']);
    LegacySeed::run(['kb']);

    expect(KbTag::count())->toBe(1)->and($existing->fresh()->only(['name', 'color']))->toBe(['name' => 'NOTFALL', 'color' => '#123456'])
        ->and(DB::table('kb_entry_tag')->count())->toBe(1);
});

it('isolates a failing entry', function () {
    LegacySeed::users([['id' => 1]]);
    LegacySeed::kbEntries([['id' => 1, 'created_by' => 5], ['id' => 2]]);
    DB::table('legacy_id_map')->insert(['entity' => 'user', 'legacy_id' => '5', 'new_id' => 999999]);

    $json = LegacySeed::run(['kb']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['kb_entry'])->toBe(['read' => 2, 'written' => 1, 'skipped' => 0, 'failed' => 1])
        ->and(kbiEntry(2)->title)->toBe('Eintrag 2')->and(kbiId('kb_entry', 1))->toBeNull()
        ->and(implode(' ', $json['failures']))->toContain('legacy entry 1')->not->toContain('insert into');
});

it('rewrites notification links to knowledge base entries through the kb id map', function () {
    LegacySeed::users([['id' => 1]]);
    LegacySeed::kbEntries([['id' => 7]]);
    LegacySeed::notifications([['id' => 1, 'link' => '/intra/lexicon/view?id=7'], ['id' => 2, 'link' => '/lexicon/view?id=8']]);

    LegacySeed::run(['kb', 'notification']);

    $links = DB::table('notifications')->get()->map(fn ($n) => json_decode($n->data, true)['link'])->sort()->values()->all();
    expect($links)->toBe(['/lexicon', '/lexicon/'.kbiId('kb_entry', 7)]);
});

it('skips the entity with a note when the legacy plugin was never installed', function () {
    LegacyDatabase::connection()->statement('RENAME TABLE intra_kb_entries TO intra_kb_entries_gone');
    try {
        $json = LegacySeed::run(['kb']);
    } finally {
        LegacyDatabase::connection()->statement('RENAME TABLE intra_kb_entries_gone TO intra_kb_entries');
    }

    expect($json['exit'])->toBe(0)->and(implode(' ', $json['notes']))->toContain('intra_kb_entries missing');
});

it('reports content that the depth or node limit cut, and headings outside h2-h4', function () {
    $deep = function (int $levels) use (&$deep): string {
        return $levels === 0 ? 'x' : '<ul><li>l'.$levels.$deep($levels - 1).'</li></ul>';
    };
    LegacySeed::kbEntries([
        ['id' => 1, 'content' => $deep(5)],
        ['id' => 2, 'content' => '<table><tr><td>'.$deep(4).'</td></tr></table>'],
        ['id' => 3, 'content' => '<table>'.str_repeat('<tr>'.str_repeat('<td>z</td>', 8).'</tr>', 1500).'</table>'],
        ['id' => 4, 'content' => str_repeat('<p>p</p>', 10000)],
        ['id' => 5, 'content' => '<h1>a</h1><h5>b</h5><h6>c</h6>'.$deep(4)],
    ]);

    $json = LegacySeed::run(['kb']);
    $w = kbiWarnings($json);

    expect($json['exit'])->toBe(0)
        ->and($w)->toContain('2 kb entr(y/ies): content cut, nested deeper than 12 levels (legacy ids: 1, 2)')
        ->toContain('2 kb entr(y/ies): content cut, more than 20000 nodes in one field (legacy ids: 3, 4)')
        ->toContain('1 kb entr(y/ies): headings outside h2-h4 kept as heading 1 (h1) or 3 (h5, h6): h1 (1), h5 (1), h6 (1) (legacy ids: 5)')
        ->not->toContain('HTML elements');
});

it('does not count an entry twice when its pass-1 write fails on a later run', function () {
    LegacySeed::users([['id' => 1]]);
    LegacySeed::kbEntries([['id' => 1, 'created_by' => 5], ['id' => 2]]);
    LegacySeed::run(['kb']);

    DB::table('legacy_id_map')->insert(['entity' => 'user', 'legacy_id' => '5', 'new_id' => 999999]);
    $json = LegacySeed::run(['kb']);

    expect($json['exit'])->toBe(1)
        ->and($json['entities']['kb_entry'])->toBe(['read' => 2, 'written' => 1, 'skipped' => 0, 'failed' => 1])
        ->and($json['failures'])->toHaveCount(1);
});

it('fails only the row whose HTML is over 8 MB and says it has no content', function () {
    LegacySeed::kbEntries([['id' => 1, 'subtitle' => 'Unter', 'content' => '<p>'.str_repeat('a', 8 * 1024 * 1024).'</p>'], ['id' => 2]]);

    $json = LegacySeed::run(['kb']);

    $one = kbiEntry(1);
    expect($json['exit'])->toBe(1)
        ->and($json['entities']['kb_entry'])->toBe(['read' => 2, 'written' => 1, 'skipped' => 0, 'failed' => 1])
        ->and(implode(' ', $json['failures']))->toContain('legacy entry 1: content not converted (HTML larger than 8 MB), the entry was imported WITHOUT content')
        ->and($one->content)->toBeNull()->and($one->search_text)->toBe("Eintrag 1\nUnter")
        ->and(kbiEntry(2)->content)->not->toBeNull();
});

it('maps a tag to the existing one when the DB reports a unique violation the PHP name check missed', function () {
    LegacySeed::kbTags([['id' => 1, 'name' => 'Alpha', 'color' => '#ff0000'], ['id' => 2, 'name' => 'Beta', 'color' => '#00ff00']]);
    // A competing 'Beta' lands right after 'Alpha' (a trigger cannot write its own table on MariaDB).
    $raced = false;
    DB::listen(function (QueryExecuted $q) use (&$raced) {
        if (! $raced && str_contains(str_replace('`', '"', $q->sql), 'insert into "kb_tags"') && in_array('Alpha', $q->bindings, true)) {
            $raced = true;
            DB::table('kb_tags')->insert(['name' => 'Beta', 'color' => '#123456', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $json = LegacySeed::run(['kb']);

    expect($json['entities']['kb_tag'])->toBe(['read' => 2, 'written' => 2, 'skipped' => 0, 'failed' => 0])
        ->and(KbTag::count())->toBe(2)->and(kbiId('kb_tag', 2))->toBe(KbTag::where('name', 'Beta')->value('id'))
        ->and(kbiWarnings($json))->toContain('1 kb tag(s) merged into a tag with the same name (ignoring case) (legacy ids: 2)');
});
