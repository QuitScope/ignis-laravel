<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\KnowledgeBase\Models\KbEntry;

require_once __DIR__.'/kbHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function kbe_pairs(): array
{
    return DB::table('kb_entry_relations')->orderBy('entry_id')->orderBy('related_entry_id')->get(['entry_id', 'related_entry_id'])->map(fn ($r) => [$r->entry_id, $r->related_entry_id])->all();
}

it('protects every write route: guests to login, accounts without the right 403, wrong right 403', function () {
    $e = kbx_entry();
    $routes = [['get', '/lexicon/create'], ['post', '/lexicon'], ['get', "/lexicon/{$e->id}/edit"], ['put', "/lexicon/{$e->id}"], ['patch', "/lexicon/{$e->id}/pin"], ['patch', "/lexicon/{$e->id}/archive"], ['patch', "/lexicon/{$e->id}/hide-editor"], ['get', '/lexicon/lookup']];
    foreach ($routes as [$m, $u]) {
        $this->$m($u)->assertRedirect('/login');
    }
    foreach ([kbx_user(), kbx_user('kb.archive')] as $u) {
        foreach ($routes as [$m, $url]) {
            if ($m === 'patch' && str_ends_with($url, 'archive') && $u->can('archive', KbEntry::class)) {
                continue;
            }
            $this->actingAs($u)->$m($url, [])->assertForbidden();
        }
    }
    $editor = kbx_user('kb.edit');
    $this->actingAs($editor)->patch("/lexicon/{$e->id}/archive")->assertForbidden();
    $this->actingAs($editor)->patch("/lexicon/{$e->id}/hide-editor")->assertForbidden();
    $this->actingAs($editor)->get('/lexicon/create')->assertOk()->assertInertia(fn (Assert $p) => $p->component('KnowledgeBase/Form')->where('entry', null));
    $this->actingAs($editor)->get("/lexicon/{$e->id}/edit")->assertOk();
    $this->actingAs(kbx_user('kb.archive'))->patch("/lexicon/{$e->id}/archive", ['value' => true])->assertRedirect();
    $this->actingAs(kbx_user('admin'))->patch("/lexicon/{$e->id}/hide-editor", ['value' => true])->assertRedirect();
});

it('creates an entry: content as JSON string, search text, tags, normalized relations, created_by', function () {
    $user = kbx_user('kb.edit');
    $cat = kbx_category('K');
    $t1 = kbx_tag('eins');
    $t2 = kbx_tag('zwei');
    $a = kbx_entry(['title' => 'A']);
    $b = kbx_entry(['title' => 'B']);

    $res = $this->actingAs($user)->post('/lexicon', [
        'type' => 'medication', 'title' => ' Adrenalin ', 'subtitle' => 'Katecholamin', 'competency_level' => 'notsan_2c', 'category_id' => $cat->id,
        'active_ingredient' => 'Epinephrin', 'drug_class' => 'Sympathomimetikum',
        'content' => kbx_doc('  Allgemeiner Text  '),
        'sections' => ['med_dosage' => kbx_doc('1 mg i.v.'), 'mass_risks' => kbx_doc('gehört nicht zum Typ')],
        'tags' => [$t1->id, $t2->id], 'related' => [$b->id, $a->id],
        'is_pinned' => true, 'hide_editor' => true, 'is_archived' => true,
    ]);
    $e = KbEntry::query()->latest('id')->firstOrFail();
    $res->assertRedirect("/lexicon/{$e->id}");

    expect($e->title)->toBe('Adrenalin')->and($e->type)->toBe('medication')->and($e->created_by)->toBe($user->id)->and($e->updated_by)->toBe($user->id)
        ->and($e->is_pinned)->toBeFalse()->and($e->hide_editor)->toBeFalse()->and($e->is_archived)->toBeFalse()
        ->and($e->content['content'][0]['content'][0]['text'])->toBe('  Allgemeiner Text  ')
        ->and(array_keys($e->sections))->toBe(['med_dosage'])
        ->and($e->search_text)->toContain('Adrenalin')->toContain('Katecholamin')->toContain('Epinephrin')->toContain('Sympathomimetikum')->toContain('Allgemeiner Text')->toContain('1 mg i.v.')->not->toContain('Risiko')
        ->and($e->tags()->pluck('kb_tags.id')->sort()->values()->all())->toBe([$t1->id, $t2->id])
        ->and(kbe_pairs())->toBe([[$a->id, $e->id], [$b->id, $e->id]]);
    $this->assertDatabaseHas('activity_log', ['log_name' => 'Wissensdatenbank', 'description' => "Wissensdatenbank-Eintrag erstellt [ID: {$e->id}]"]);
});

it('stores a measure with its sections and ignores medication columns', function () {
    $this->actingAs(kbx_user('kb.edit'))->post('/lexicon', ['type' => 'measure', 'title' => 'Intubation', 'active_ingredient' => 'x', 'sections' => ['mass_procedure' => kbx_doc('Schritt 1')]])->assertRedirect();
    $e = KbEntry::query()->firstOrFail();
    expect($e->active_ingredient)->toBeNull()->and(array_keys($e->sections))->toBe(['mass_procedure'])->and($e->search_text)->toContain('Schritt 1');
});

it('validates: title, type, enums, unknown ids, content as 422 on the field, never a DB error', function () {
    $user = kbx_user('kb.edit');
    $e = kbx_entry();
    $bad = fn (array $data, string ...$keys) => $this->actingAs($user)->postJson('/lexicon', $data + ['type' => 'general', 'title' => 'T'])->assertUnprocessable()->assertJsonValidationErrors($keys);

    $bad(['title' => ''], 'title');
    $bad(['title' => str_repeat('x', 256)], 'title');
    $bad(['type' => 'bogus'], 'type');
    $bad(['competency_level' => 'god'], 'competency_level');
    $bad(['category_id' => 9999], 'category_id');
    $bad(['tags' => [9999]], 'tags.0');
    $bad(['related' => [9999]], 'related.0');
    $bad(['content' => '{not json'], 'content');
    $bad(['content' => json_encode(['type' => 'doc', 'content' => [['type' => 'iframe']]])], 'content');
    $bad(['content' => json_encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]]]])], 'content');
    $bad(['content' => ['type' => 'doc']], 'content');
    $bad(['type' => 'medication', 'sections' => ['med_dosage' => '[]']], 'sections.med_dosage');
    $bad(['type' => 'medication', 'sections' => ['med_dosage' => json_encode(['type' => 'doc', 'content' => [['type' => 'script']]])]], 'sections.med_dosage');
    $bad(['content' => str_repeat('a', 2 * 1024 * 1024 + 10)], 'content');
    expect(KbEntry::count())->toBe(1);
    $this->actingAs($user)->putJson("/lexicon/{$e->id}", ['related' => [$e->id]])->assertUnprocessable()->assertJsonValidationErrors(['related.0']);
});

it('answers a validation error without any database text', function () {
    $r = $this->actingAs(kbx_user('kb.edit'))->postJson('/lexicon', ['type' => 'general', 'title' => 'T', 'content' => json_encode(['type' => 'doc', 'content' => [['type' => 'iframe']]])]);
    expect($r->getContent())->not->toContain('SQLSTATE')->not->toContain('kb_entries');
});

it('keeps stored values for fields that are not sent, and clears the ones sent as null', function () {
    $user = kbx_user('kb.edit');
    $cat = kbx_category('K');
    $tag = kbx_tag('t');
    $other = kbx_entry(['title' => 'Other']);
    $this->actingAs($user)->post('/lexicon', [
        'type' => 'medication', 'title' => 'Alt', 'subtitle' => 'Sub', 'category_id' => $cat->id, 'active_ingredient' => 'Wirk', 'drug_class' => 'Klasse', 'competency_level' => 'basis',
        'content' => kbx_doc('Inhalt'), 'sections' => ['med_dosage' => kbx_doc('Dosis'), 'med_indications' => kbx_doc('Indik')], 'tags' => [$tag->id], 'related' => [$other->id],
    ]);
    $e = KbEntry::query()->latest('id')->firstOrFail();

    $this->actingAs($user)->put("/lexicon/{$e->id}", ['title' => 'Neu'])->assertRedirect("/lexicon/{$e->id}");
    $e->refresh();
    expect($e->title)->toBe('Neu')->and($e->subtitle)->toBe('Sub')->and($e->category_id)->toBe($cat->id)->and($e->active_ingredient)->toBe('Wirk')->and($e->competency_level)->toBe('basis')
        ->and($e->content['content'][0]['content'][0]['text'])->toBe('Inhalt')->and(array_keys($e->sections))->toBe(['med_indications', 'med_dosage'])
        ->and($e->tags()->count())->toBe(1)->and(kbe_pairs())->toBe([[$other->id, $e->id]])
        ->and($e->search_text)->toContain('Neu')->toContain('Inhalt')->toContain('Dosis')->toContain('Indik');

    $this->actingAs($user)->put("/lexicon/{$e->id}", ['sections' => ['med_dosage' => kbx_doc('Neue Dosis'), 'med_indications' => null], 'subtitle' => null, 'tags' => [], 'related' => []])->assertRedirect();
    $e->refresh();
    expect(array_keys($e->sections))->toBe(['med_dosage'])->and($e->subtitle)->toBeNull()->and($e->tags()->count())->toBe(0)->and(kbe_pairs())->toBe([])
        ->and($e->search_text)->toContain('Neue Dosis')->not->toContain('Indik')->not->toContain('Sub');
    $this->assertDatabaseHas('activity_log', ['log_name' => 'Wissensdatenbank', 'description' => "Wissensdatenbank-Eintrag bearbeitet [ID: {$e->id}]"]);
});

it('keeps type, pin, archive and hide_editor on update by a non-admin, and sets updated_by but not created_by', function () {
    $author = kbx_user('kb.edit');
    $editor = kbx_user('kb.edit');
    $e = kbx_entry(['type' => 'general', 'is_pinned' => true, 'hide_editor' => true, 'created_by' => $author->id]);

    $this->actingAs($editor)->put("/lexicon/{$e->id}", ['title' => 'Neu', 'type' => 'measure', 'is_pinned' => false, 'hide_editor' => false, 'is_archived' => true, 'created_by' => $editor->id])->assertRedirect();
    $e->refresh();
    expect($e->type)->toBe('general')->and($e->is_pinned)->toBeTrue()->and($e->hide_editor)->toBeTrue()->and($e->is_archived)->toBeFalse()
        ->and($e->created_by)->toBe($author->id)->and($e->updated_by)->toBe($editor->id);
});

it('replaces relations in both directions, normalizes the pair, ignores duplicates, rejects the entry itself', function () {
    $user = kbx_user('kb.edit');
    $a = kbx_entry(['title' => 'A']);
    $b = kbx_entry(['title' => 'B']);
    $c = kbx_entry(['title' => 'C']);
    DB::table('kb_entry_relations')->insert(['entry_id' => $a->id, 'related_entry_id' => $b->id]);

    $this->actingAs($user)->put("/lexicon/{$b->id}", ['related' => [$c->id, $c->id]])->assertRedirect();
    expect(kbe_pairs())->toBe([[$b->id, $c->id]]);
    $this->actingAs($user)->put("/lexicon/{$c->id}", ['related' => [$a->id, $b->id]])->assertRedirect();
    expect(kbe_pairs())->toBe([[$a->id, $c->id], [$b->id, $c->id]]);
    $this->actingAs($user)->putJson("/lexicon/{$c->id}", ['related' => [$c->id]])->assertUnprocessable();
    expect(kbe_pairs())->toHaveCount(2);
});

it('the edit form carries the stored data including archived relations', function () {
    $user = kbx_user('kb.edit', 'kb.archive');
    $a = kbx_entry(['title' => 'A', 'content' => ['type' => 'doc', 'content' => []]]);
    $b = kbx_entry(['title' => 'B', 'is_archived' => true]);
    DB::table('kb_entry_relations')->insert(['entry_id' => $a->id, 'related_entry_id' => $b->id]);
    $this->actingAs($user)->get("/lexicon/{$a->id}/edit")->assertInertia(fn (Assert $p) => $p->component('KnowledgeBase/Form')->where('entry.title', 'A')->where('entry.related.0.title', 'B')->where('entry.related.0.is_archived', true)
        ->has('sectionsByType.medication', 6)->has('competencyLevels', 5));
    $this->actingAs($user)->get('/lexicon/99999/edit')->assertNotFound();
});

it('pins, archives and hides the editor to the sent value, redirects to list or detail (never the referer), audits only real changes', function () {
    $e = kbx_entry(['updated_at' => '2026-01-01 10:00:00']);
    $editor = kbx_user('kb.edit');
    $admin = kbx_user('admin');
    $audits = fn (string $word) => DB::table('activity_log')->where('description', "Wissensdatenbank-Eintrag {$word} [ID: {$e->id}]")->count();

    $this->actingAs($editor)->from('/evil')->patch("/lexicon/{$e->id}/pin", ['value' => true])->assertRedirect("/lexicon/{$e->id}");
    $this->actingAs($editor)->patch("/lexicon/{$e->id}/pin", ['value' => true])->assertRedirect();
    expect($e->fresh()->is_pinned)->toBeTrue()->and($audits('angepinnt'))->toBe(1);
    $this->actingAs($editor)->from('/evil')->patch("/lexicon/{$e->id}/pin", ['value' => false, 'return' => 'list'])->assertRedirect('/lexicon');
    expect($e->fresh()->is_pinned)->toBeFalse();
    $this->actingAs($editor)->patchJson("/lexicon/{$e->id}/pin")->assertUnprocessable();
    $this->actingAs($editor)->patch("/lexicon/{$e->id}/pin", ['value' => false, 'return' => 'http://evil.test'])->assertRedirect("/lexicon/{$e->id}");

    $this->actingAs($admin)->patch("/lexicon/{$e->id}/archive", ['value' => true])->assertRedirect("/lexicon/{$e->id}");
    expect($e->fresh()->is_archived)->toBeTrue();
    $this->actingAs($admin)->patch("/lexicon/{$e->id}/archive", ['value' => false, 'return' => 'list'])->assertRedirect('/lexicon');
    expect($e->fresh()->is_archived)->toBeFalse();

    $this->actingAs($admin)->patch("/lexicon/{$e->id}/hide-editor", ['value' => true])->assertRedirect();
    expect($e->fresh()->hide_editor)->toBeTrue()->and($e->fresh()->updated_at->toDateTimeString())->toBe('2026-01-01 10:00:00');

    foreach (['angepinnt', 'losgelöst', 'archiviert', 'wiederhergestellt'] as $word) {
        expect($audits($word))->toBe(1);
    }
    $this->actingAs($admin)->patch('/lexicon/99999/pin', ['value' => true])->assertNotFound();
});

it('hides archived entries from kb.edit-only accounts on edit, update and pin (404), kb.archive and admin can reach them', function () {
    $e = kbx_entry(['title' => 'Alt', 'is_archived' => true]);
    $editor = kbx_user('kb.edit');
    $this->actingAs($editor)->get("/lexicon/{$e->id}/edit")->assertNotFound();
    $this->actingAs($editor)->put("/lexicon/{$e->id}", ['title' => 'X'])->assertNotFound();
    $this->actingAs($editor)->patch("/lexicon/{$e->id}/pin", ['value' => true])->assertNotFound();
    expect($e->fresh()->title)->toBe('Alt')->and($e->fresh()->is_pinned)->toBeFalse();

    $both = kbx_user('kb.edit', 'kb.archive');
    $this->actingAs($both)->get("/lexicon/{$e->id}/edit")->assertOk();
    $this->actingAs($both)->put("/lexicon/{$e->id}", ['title' => 'Neu'])->assertRedirect();
    $this->actingAs($both)->patch("/lexicon/{$e->id}/pin", ['value' => true])->assertRedirect();
    $admin = kbx_user('admin');
    $this->actingAs($admin)->get("/lexicon/{$e->id}/edit")->assertOk();
    $this->actingAs($admin)->patch("/lexicon/{$e->id}/archive", ['value' => false])->assertRedirect();
    expect($e->fresh()->is_archived)->toBeFalse();
    $this->actingAs($editor)->get("/lexicon/{$e->id}/edit")->assertOk();
});

it('can still update the title of an entry whose stored section no longer validates', function () {
    $e = kbx_entry(['type' => 'medication', 'title' => 'Alt', 'sections' => ['med_dosage' => ['type' => 'doc', 'content' => [['type' => 'iframe']]]]]);
    $this->actingAs(kbx_user('kb.edit'))->put("/lexicon/{$e->id}", ['title' => 'Neu'])->assertRedirect("/lexicon/{$e->id}");
    expect($e->fresh()->title)->toBe('Neu')->and($e->fresh()->sections['med_dosage']['content'][0]['type'])->toBe('iframe');
});

it('looks up non-archived entries by title, at most 10, without the entry itself', function () {
    $user = kbx_user('kb.edit');
    $self = kbx_entry(['title' => 'Adrenalin self']);
    kbx_entry(['title' => 'Adrenalin archiv', 'is_archived' => true]);
    for ($i = 0; $i < 12; $i++) {
        kbx_entry(['title' => 'Adrenalin '.$i]);
    }
    $r = $this->actingAs($user)->getJson('/lexicon/lookup?q=adrenalin&exclude='.$self->id)->assertOk();
    expect($r->json())->toHaveCount(10)->and(collect($r->json())->pluck('title')->all())->not->toContain('Adrenalin self')->not->toContain('Adrenalin archiv')
        ->and($r->json('0'))->toHaveKeys(['id', 'title', 'type']);
    expect($this->actingAs($user)->getJson('/lexicon/lookup?q=%25')->json())->toBe([]);
    $this->actingAs($user)->getJson('/lexicon/lookup?q[]=x&exclude[]=1')->assertOk();
});

it('keeps the fixed paths ahead of the wildcard', function () {
    $user = kbx_user('kb.edit');
    $this->actingAs($user)->get('/lexicon/taxonomy')->assertOk()->assertInertia(fn (Assert $p) => $p->component('KnowledgeBase/Taxonomy'));
    $this->actingAs($user)->get('/lexicon/create')->assertInertia(fn (Assert $p) => $p->component('KnowledgeBase/Form'));
    $this->actingAs($user)->get('/lexicon/images')->assertStatus(405);
});

it('hides archived relations in the form and refuses archived ids for an editor without kb.archive', function () {
    $edit = kbx_user('kb.edit');
    $a = kbx_entry(['title' => 'A']);
    $b = kbx_entry(['title' => 'B', 'is_archived' => true]);
    DB::table('kb_entry_relations')->insert(['entry_id' => $a->id, 'related_entry_id' => $b->id]);

    $this->actingAs($edit)->get("/lexicon/{$a->id}/edit")->assertInertia(fn (Assert $p) => $p->has('entry.related', 0));
    $this->actingAs($edit)->putJson("/lexicon/{$a->id}", ['related' => [$b->id]])->assertUnprocessable()->assertJsonValidationErrors(['related.0']);

    $this->actingAs(kbx_user('kb.edit', 'kb.archive'))->put("/lexicon/{$a->id}", ['related' => [$b->id]])->assertRedirect();

    $c = kbx_entry(['title' => 'C']);
    $this->actingAs($edit)->put("/lexicon/{$a->id}", ['related' => [$c->id]])->assertRedirect();
    expect(kbe_pairs())->toBe([[$a->id, $b->id], [$a->id, $c->id]]);
});
