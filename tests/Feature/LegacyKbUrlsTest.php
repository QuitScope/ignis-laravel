<?php

use App\Http\LegacyUrlMap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects the old knowledge base pages', function () {
    foreach ([
        ['/lexicon/index', '/lexicon'],
        ['/lexicon/index.php', '/lexicon'],
        ['/lexicon/index?type=medication', '/lexicon?type=medication'],
        ['/wissensdb/index.php', '/lexicon'],
        ['/lexicon/manage-taxonomy', '/lexicon/taxonomy'],
        ['/lexicon/manage-taxonomy.php', '/lexicon/taxonomy'],
        ['/lexicon/view?id=12', '/lexicon'],
        ['/lexicon/view.php?id=12', '/lexicon'],
        ['/lexicon/edit?id=12', '/lexicon'],
        ['/wissensdb/view?id=12', '/lexicon'],
    ] as [$from, $to]) {
        $this->get($from)->assertStatus(301)->assertRedirect($to);
    }
});

it('redirects the old knowledge base root with its trailing slash', function () {
    expect(LegacyUrlMap::redirectFor('/lexicon/'))->toBe(['/lexicon', 301]);
});

it('keeps the new pages that share the old paths', function () {
    $this->actingAs(User::factory()->create())->get('/lexicon')->assertOk();
    $this->get('/lexicon/create')->assertForbidden();
});

it('has no legacy POST endpoints or knowledge base API any more', function () {
    foreach ([
        ['POST', '/lexicon/create', 405],
        ['POST', '/lexicon/edit', 404],
        ['POST', '/lexicon/archive', 404],
        ['POST', '/lexicon/pin', 404],
        ['POST', '/lexicon/toggle-editor', 404],
        ['GET', '/api/knowledgebase/categories', 404],
        ['POST', '/api/knowledgebase/categories', 404],
        ['DELETE', '/api/knowledgebase/categories', 404],
        ['GET', '/api/knowledgebase/tags', 404],
        ['POST', '/api/knowledgebase/tags', 404],
        ['DELETE', '/api/knowledgebase/tags', 404],
        ['POST', '/api/knowledgebase/images', 404],
        ['GET', '/api/knowledgebase/search', 404],
        ['GET', '/api/v1/knowledgebase/search', 404],
    ] as [$method, $uri, $status]) {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]))
            ->call($method, $uri, ['id' => 1, 'csrf_token' => 'x'])->assertStatus($status);
    }
});
