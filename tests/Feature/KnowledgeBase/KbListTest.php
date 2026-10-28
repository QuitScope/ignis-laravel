<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/kbHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('sends guests to the login while public access is off, and shows the list when it is on', function () {
    kbx_entry(['title' => 'Offen']);
    $this->get('/lexicon')->assertRedirect('/login');

    kbx_public();
    $this->get('/lexicon')->assertOk()->assertInertia(fn (Assert $p) => $p->component('KnowledgeBase/Index')->has('entries.data', 1)->where('can.edit', false));
});

it('lets any account read without kb.view, and hides the archive from everyone but kb.archive and admin', function () {
    kbx_entry(['title' => 'Offen']);
    kbx_entry(['title' => 'Alt', 'is_archived' => true]);

    foreach ([kbx_user('audit.view'), kbx_user('kb.edit')] as $u) {
        $this->actingAs($u)->get('/lexicon')->assertInertia(fn (Assert $p) => $p->has('entries.data', 1)->where('entries.data.0.title', 'Offen')->where('can.archive', false));
        $this->actingAs($u)->get('/lexicon?archived=1')->assertInertia(fn (Assert $p) => $p->has('entries.data', 1)->where('filters.archived', false)->where('entries.data.0.title', 'Offen'));
    }
    foreach ([kbx_user('kb.archive'), kbx_user('admin')] as $u) {
        $this->actingAs($u)->get('/lexicon')->assertInertia(fn (Assert $p) => $p->has('entries.data', 1)->where('can.archive', true));
        $this->actingAs($u)->get('/lexicon?archived=1')->assertInertia(fn (Assert $p) => $p->has('entries.data', 1)->where('entries.data.0.title', 'Alt'));
    }
});

it('guests never get the archive filter', function () {
    kbx_public();
    kbx_entry(['is_archived' => true]);
    $this->get('/lexicon?archived=1')->assertInertia(fn (Assert $p) => $p->has('entries.data', 0)->where('filters.archived', false));
});

it('filters by type, tag and text (title, subtitle, search text, tag name), wildcards literal', function () {
    $user = kbx_user();
    $tag = kbx_tag('Atmung');
    kbx_entry(['title' => 'Adrenalin', 'type' => 'medication', 'search_text' => 'adrenalin anaphylaxie']);
    $m = kbx_entry(['title' => 'Intubation', 'type' => 'measure', 'subtitle' => 'Tubus']);
    $m->tags()->attach($tag->id);
    kbx_entry(['title' => '100% sicher', 'search_text' => '100% sicher']);
    kbx_entry(['title' => 'Plain', 'search_text' => 'plain']);

    $titles = fn (string $qs) => collect(kbx_props($this->actingAs($user)->get('/lexicon'.$qs))['entries']['data'])->pluck('title')->sort()->values()->all();
    expect($titles('?type=medication'))->toBe(['Adrenalin'])
        ->and($titles('?type=bogus'))->toHaveCount(4)
        ->and($titles('?tag='.$tag->id))->toBe(['Intubation'])
        ->and($titles('?q=anaphyl'))->toBe(['Adrenalin'])
        ->and($titles('?q=tubus'))->toBe(['Intubation'])
        ->and($titles('?q=atmung'))->toBe(['Intubation'])
        ->and($titles('?q=%25'))->toBe(['100% sicher'])
        ->and($titles('?q=_'))->toBe([])
        ->and($titles('?q[]=x&type[]=y'))->toHaveCount(4);
});

it('filters a category including all its descendants, and survives a loop in the data', function () {
    $user = kbx_user();
    $root = kbx_category('Wurzel');
    $kid = kbx_category('Kind', $root->id);
    $grandkid = kbx_category('Enkel', $kid->id);
    $other = kbx_category('Andere');
    kbx_entry(['title' => 'A', 'category_id' => $root->id]);
    kbx_entry(['title' => 'B', 'category_id' => $kid->id]);
    kbx_entry(['title' => 'C', 'category_id' => $grandkid->id]);
    kbx_entry(['title' => 'D', 'category_id' => $other->id]);
    kbx_entry(['title' => 'E']);

    $titles = fn (int $id) => collect(kbx_props($this->actingAs($user)->get('/lexicon?category='.$id))['entries']['data'])->pluck('title')->sort()->values()->all();
    expect($titles($root->id))->toBe(['A', 'B', 'C'])->and($titles($kid->id))->toBe(['B', 'C'])->and($titles($grandkid->id))->toBe(['C']);

    DB::table('kb_categories')->where('id', $root->id)->update(['parent_id' => $grandkid->id]);
    expect($titles($root->id))->toBe(['A', 'B', 'C']);
    $this->actingAs($user)->get('/lexicon')->assertOk()->assertInertia(fn (Assert $p) => $p->has('categories', 4));
});

it('sorts pinned first, then updated_at, then created_at', function () {
    $user = kbx_user();
    kbx_entry(['title' => 'old', 'updated_at' => '2026-01-01 10:00:00', 'created_at' => '2026-01-01 10:00:00']);
    kbx_entry(['title' => 'new', 'updated_at' => '2026-03-01 10:00:00', 'created_at' => '2026-01-02 10:00:00']);
    kbx_entry(['title' => 'pinned', 'is_pinned' => true, 'updated_at' => '2025-01-01 10:00:00', 'created_at' => '2025-01-01 10:00:00']);
    kbx_entry(['title' => 'tie', 'updated_at' => '2026-01-01 10:00:00', 'created_at' => '2026-02-01 10:00:00']);

    $order = collect(kbx_props($this->actingAs($user)->get('/lexicon'))['entries']['data'])->pluck('title')->all();
    expect($order)->toBe(['pinned', 'new', 'tie', 'old']);
});

it('paginates by 25 and keeps the query string', function () {
    $user = kbx_user();
    for ($i = 0; $i < 30; $i++) {
        kbx_entry(['title' => 'Medi '.$i, 'type' => 'medication']);
    }
    $this->actingAs($user)->get('/lexicon?type=medication')->assertInertia(fn (Assert $p) => $p->has('entries.data', 25)->where('entries.total', 30)
        ->where('entries.next_page_url', fn ($u) => str_contains($u, 'type=medication') && str_contains($u, 'page=2')));
    $this->actingAs($user)->get('/lexicon?type=medication&page=2')->assertInertia(fn (Assert $p) => $p->has('entries.data', 5));
});

it('runs a constant number of queries whatever the number of entries', function () {
    $user = kbx_user();
    $cat = kbx_category('K');
    $tags = [kbx_tag('a'), kbx_tag('b')];
    $make = function (int $n) use ($cat, $tags) {
        for ($i = 0; $i < $n; $i++) {
            $e = kbx_entry(['category_id' => $cat->id, 'competency_level' => 'basis']);
            $e->tags()->attach(array_map(fn ($t) => $t->id, $tags));
        }
    };
    $count = function () use ($user) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get('/lexicon?q=x')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $make(3);
    $count();
    $few = $count();
    $make(27);
    expect($count())->toBe($few);
});

it('tells the page whether the taxonomy link may show (kb.edit only)', function () {
    $this->actingAs(kbx_user('kb.edit'))->get('/lexicon')->assertInertia(fn (Assert $p) => $p->where('can.edit', true));
    $this->actingAs(kbx_user())->get('/lexicon')->assertInertia(fn (Assert $p) => $p->where('can.edit', false));
});
