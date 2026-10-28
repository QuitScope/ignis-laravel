<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\KnowledgeBase\Models\KbEntryRelation;

require_once __DIR__.'/kbHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function kbs_doc(string $text): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

it('shows rights: guest off redirects (even for an unknown id), guest on reads, accounts read, module off is 404', function () {
    $e = kbx_entry(['title' => 'Adrenalin']);
    $this->get("/lexicon/{$e->id}")->assertRedirect('/login');
    $this->get('/lexicon/99999')->assertRedirect('/login');

    kbx_public();
    $this->get("/lexicon/{$e->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('KnowledgeBase/Show')->where('entry.title', 'Adrenalin')->where('can', null));
    $this->get('/lexicon/99999')->assertNotFound();
    $this->get('/lexicon/abc')->assertNotFound();

    $this->actingAs(kbx_user())->get("/lexicon/{$e->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('can.edit', false)->where('can.archive', false)->where('can.hideEditor', false));
    $this->actingAs(kbx_user('kb.edit'))->get("/lexicon/{$e->id}")->assertInertia(fn (Assert $p) => $p->where('can.edit', true));
    $this->actingAs(kbx_user('admin'))->get("/lexicon/{$e->id}")->assertInertia(fn (Assert $p) => $p->where('can.hideEditor', true)->where('entry.hide_editor', false));

    app(Settings::class)->set('modules.knowledgebase.enabled', false);
    $this->get("/lexicon/{$e->id}")->assertNotFound();
});

it('hides archived entries: 404 for guests and plain accounts, readable for kb.archive and admin', function () {
    $e = kbx_entry(['is_archived' => true]);
    kbx_public();
    $this->get("/lexicon/{$e->id}")->assertNotFound();
    $this->actingAs(kbx_user('kb.edit'))->get("/lexicon/{$e->id}")->assertNotFound();
    $this->actingAs(kbx_user('kb.archive'))->get("/lexicon/{$e->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('entry.is_archived', true));
    $this->actingAs(kbx_user('admin'))->get("/lexicon/{$e->id}")->assertOk();
});

it('shows relations in both directions without archived entries', function () {
    $a = kbx_entry(['title' => 'A']);
    $b = kbx_entry(['title' => 'B']);
    $c = kbx_entry(['title' => 'C']);
    $gone = kbx_entry(['title' => 'Gone', 'is_archived' => true]);
    foreach ([[$a, $b], [$c, $a], [$a, $gone]] as [$x, $y]) {
        [$lo, $hi] = KbEntryRelation::normalize($x->id, $y->id);
        DB::table('kb_entry_relations')->insert(['entry_id' => $lo, 'related_entry_id' => $hi]);
    }
    $user = kbx_user();
    $this->actingAs($user)->get("/lexicon/{$a->id}")->assertInertia(fn (Assert $p) => $p->has('related', 2)->where('related.0.title', 'B')->where('related.1.title', 'C'));
    $this->actingAs($user)->get("/lexicon/{$b->id}")->assertInertia(fn (Assert $p) => $p->has('related', 1)->where('related.0.title', 'A'));
    $this->actingAs($user)->get("/lexicon/{$gone->id}")->assertNotFound();
});

it('shows category path, tags and competency colour', function () {
    $root = kbx_category('Medikamente');
    $kid = kbx_category('Notfall', $root->id);
    $tag = kbx_tag('Herz');
    $e = kbx_entry(['category_id' => $kid->id, 'competency_level' => 'notsan_2a']);
    $e->tags()->attach($tag->id);

    $this->actingAs(kbx_user())->get("/lexicon/{$e->id}")->assertInertia(fn (Assert $p) => $p
        ->where('categoryPath.0.name', 'Medikamente')->where('categoryPath.1.name', 'Notfall')
        ->where('tags.0.name', 'Herz')->where('entry.competency.label', 'NFS 2a')->where('entry.competency.color', '#ffc000'));

    DB::table('kb_categories')->where('id', $root->id)->update(['parent_id' => $kid->id]);
    $this->actingAs(kbx_user())->get("/lexicon/{$e->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->has('categoryPath', 2));
});

it('shows creator and editor names to accounts only, and never when hide_editor is set', function () {
    $author = kbs_named('Erika Autorin');
    $e = kbx_entry(['created_by' => $author->id, 'updated_by' => $author->id]);

    kbx_public();
    $guest = $this->get("/lexicon/{$e->id}");
    $guest->assertInertia(fn (Assert $p) => $p->where('entry.creator', null)->where('entry.editor', null));
    expect($guest->getContent())->not->toContain('Erika Autorin')->not->toContain($author->username);

    $this->actingAs(kbx_user())->get("/lexicon/{$e->id}")->assertInertia(fn (Assert $p) => $p->where('entry.creator', 'Erika Autorin')->where('entry.editor', 'Erika Autorin'));

    DB::table('kb_entries')->where('id', $e->id)->update(['hide_editor' => true]);
    $this->actingAs(kbx_user())->get("/lexicon/{$e->id}")->assertInertia(fn (Assert $p) => $p->where('entry.creator', null)->where('entry.editor', null)->where('entry.hide_editor', null));
    $this->actingAs(kbx_user('admin'))->get("/lexicon/{$e->id}")->assertInertia(fn (Assert $p) => $p->where('entry.creator', null)->where('entry.hide_editor', true));
});

function kbs_named(string $name): User
{
    return User::factory()->create(['name' => $name]);
}

it('shows the sections of the entry type first, then any other non-empty one, skipping empty ones', function () {
    $e = kbx_entry([
        'type' => 'medication', 'active_ingredient' => 'Adrenalin',
        'content' => kbs_doc('Allgemein'),
        'sections' => [
            'med_dosage' => kbs_doc('1 mg'), 'med_indications' => kbs_doc('Reanimation'),
            'mass_risks' => kbs_doc('Altlast'), 'med_side_effects' => ['type' => 'doc', 'content' => []],
        ],
    ]);
    $this->actingAs(kbx_user())->get("/lexicon/{$e->id}")->assertInertia(fn (Assert $p) => $p
        ->where('entry.active_ingredient', 'Adrenalin')
        ->where('sectionsHtml', fn ($s) => collect($s)->pluck('key')->all() === ['med_indications', 'med_dosage', 'mass_risks'])
        ->where('contentHtml', '<p>Allgemein</p>'));
});

it('renders stored malicious JSON only through the renderer: no script, handler or javascript link survives', function () {
    $evil = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => '<script>alert(1)</script>'],
            ['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
            ['type' => 'text', 'text' => 'link2', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test/"onmouseover="alert(1)', 'target' => '_blank']]]],
        ]],
        ['type' => 'image', 'attrs' => ['src' => 'https://evil.test/x.png', 'alt' => '"><img src=x onerror=alert(1)>']],
        ['type' => 'image', 'attrs' => ['src' => '/storage/kb-images/'.str_repeat('a', 32).'.png', 'alt' => '"><img src=x onerror=alert(2)>']],
        ['type' => 'iframe', 'attrs' => ['src' => 'https://evil.test']],
        ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'onerror=alert(3)']]],
    ]];
    $e = kbx_entry(['content' => $evil, 'sections' => ['med_dosage' => $evil]]);

    $page = kbx_props($this->actingAs(kbx_user())->get("/lexicon/{$e->id}"));
    foreach ([$page['contentHtml'], $page['sectionsHtml'][0]['html']] as $html) {
        expect($html)->not->toContain('<script')->not->toContain('<iframe')->not->toContain('javascript:')
            ->not->toContain('evil.test')->toContain('&lt;script&gt;');
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NOERROR);
        foreach ((new DOMXPath($dom))->query('//body//*') as $el) {
            expect($el->nodeName)->toBeIn(['p', 'a', 'img', 'ul', 'ol', 'li', 'strong', 'em', 'u', 's', 'br', 'hr', 'blockquote', 'h1', 'h2', 'h3', 'table', 'tbody', 'tr', 'td', 'th', 'colgroup', 'col']);
            foreach ($el->attributes as $attr) {
                expect($attr->nodeName)->toBeIn(['src', 'alt', 'href', 'target', 'rel', 'loading', 'colspan', 'rowspan', 'start']);
            }
        }
    }
});

it('does not fail on stored content that is no valid document', function () {
    $e = kbx_entry(['content' => ['foo' => 'bar'], 'sections' => ['med_dosage' => ['type' => 'nope']]]);
    $this->actingAs(kbx_user())->get("/lexicon/{$e->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('contentHtml', '')->has('sectionsHtml', 0));
});
